<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Turns the files of a chat message into content blocks for the model: images and PDFs go as they are, text of any kind
 * (by content, not by extension), Office / OpenDocument files and the text files inside a zip are extracted to text.
 * What cannot be read is returned as a note naming the file and why.
 */
class AttachmentReader
{
    public const MAX_FILES = 10;

    /** Per file, in KB (the validation rule unit). */
    public const MAX_KB = 25600;

    /** Never accepted: programs and installers. */
    public const BLOCKED = ['exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'dll', 'jar', 'apk', 'dmg', 'app', 'pif', 'vbs'];

    private const API_IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** The API takes at most 5 MB per image. */
    private const IMAGE_BYTES = 5 * 1024 * 1024;

    /** Images and PDFs together: the API request is capped at 32 MB and base64 adds a third. */
    private const BINARY_BUDGET = 22 * 1024 * 1024;

    /** Characters of text taken from one file, and from all the files of a message (about 100k tokens). */
    private const FILE_CHARS = 150000;

    private const TOTAL_CHARS = 400000;

    /** Largest XML part of an Office file that is read (a guard against zip bombs). */
    private const XML_BYTES = 40 * 1024 * 1024;

    private const ZIP_FILES = 25;

    private const ZIP_SKIP = '#(^|/)(__MACOSX|node_modules|vendor|\.git|\.DS_Store)(/|$)#';

    /** Validation rule: any file except programs and installers. */
    public static function allowed(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value instanceof UploadedFile && in_array(strtolower($value->getClientOriginalExtension()), self::BLOCKED, true)) {
            $fail(__('ui.errors.file_type', ['name' => $value->getClientOriginalName()]));
        }
    }

    private int $binary = 0;

    private int $chars = 0;

    /**
     * @param  list<UploadedFile>  $files
     * @return array{blocks:list<array>,notes:list<string>}
     */
    public function read(array $files): array
    {
        $this->binary = 0;
        $this->chars = 0;
        $blocks = [];
        $notes = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            try {
                $result = $this->one($file, $name);
            } catch (\Throwable $e) {
                report($e);
                $result = "$name (could not be read)";
            }
            if (is_string($result)) {
                $notes[] = $result;
            } else {
                $blocks[] = $result;
            }
        }

        return ['blocks' => $blocks, 'notes' => $notes];
    }

    /** @return array|string a content block, or a note when the file cannot be read */
    private function one(UploadedFile $file, string $name): array|string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return $this->binaryBlock('document', 'application/pdf', $file->get(), $name);
        }

        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return $this->image($file->get(), $mime, $name);
        }

        $text = match ($ext) {
            'docx', 'dotx' => $this->office($file->getRealPath(), ['word/document.xml'], '</w:p>', ['<w:tab/>' => "\t", '<w:br/>' => "\n"]),
            'pptx' => $this->pptx($file->getRealPath()),
            'xlsx', 'xlsm' => $this->xlsx($file->getRealPath()),
            'odt', 'ods', 'odp' => $this->office($file->getRealPath(), ['content.xml'], '</text:p>', ['</text:h>' => "\n", '</table:table-row>' => "\n", '</table:table-cell>' => "\t"]),
            'zip' => $this->zip($file->getRealPath()),
            'doc', 'xls', 'ppt' => null, // the old binary formats
            default => $this->plain($file->get(), $size),
        };

        if ($text === null || trim($text) === '') {
            return "$name (not readable: save it as a newer format or paste the content)";
        }

        return $this->textBlock($name, $text);
    }

    private function binaryBlock(string $type, string $mime, string $data, string $name): array|string
    {
        if ($this->binary + strlen($data) > self::BINARY_BUDGET) {
            return "$name (too large to send to the assistant together with the other files)";
        }
        $this->binary += strlen($data);

        return ['type' => $type, 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)]];
    }

    private function image(string $data, string $mime, string $name): array|string
    {
        if (in_array($mime, self::API_IMAGES, true) && strlen($data) <= self::IMAGE_BYTES) {
            return $this->binaryBlock('image', $mime, $data, $name);
        }

        // too big, or a format the API does not know (bmp, avif, ...): re-encode as a JPEG of at most 2000 px
        $dim = function_exists('getimagesizefromstring') ? @getimagesizefromstring($data) : false;
        if (! $dim || ! function_exists('imagecreatefromstring') || ! $this->fitsInMemory($dim[0] * $dim[1] * 5) || ! ($img = @imagecreatefromstring($data))) {
            return "$name (image format or size not supported)";
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, 2000 / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start();
        imagejpeg($out, null, 85);
        $jpeg = (string) ob_get_clean();

        return strlen($jpeg) <= self::IMAGE_BYTES ? $this->binaryBlock('image', 'image/jpeg', $jpeg, $name) : "$name (image too large)";
    }

    private function fitsInMemory(int $bytes): bool
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        return $limit <= 0 || $bytes < $limit - memory_get_usage() - 8 * 1024 * 1024;
    }

    private function textBlock(string $name, string $text): array|string
    {
        $room = self::TOTAL_CHARS - $this->chars;
        if ($room <= 0) {
            return "$name (left out: the text of the other files already fills what the assistant can read)";
        }
        $limit = min(self::FILE_CHARS, $room);
        $length = mb_strlen($text);
        $cut = $length > $limit;
        $text = $cut ? mb_substr($text, 0, $limit)."\n[… the rest of the file ($length characters in all) was left out …]" : $text;
        $this->chars += min($length, $limit);

        return ['type' => 'text', 'text' => "File \"$name\":\n```\n$text\n```"];
    }

    /** A file is text when it has no NUL byte and is valid UTF-8 (or Latin-1, which old exports use). */
    private function plain(string $data, int $size): ?string
    {
        // only the first part is needed: the rest is cut anyway
        $data = substr($data, 0, self::FILE_CHARS * 4);
        if (str_contains(substr($data, 0, 8192), "\0")) {
            return null;
        }
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }
        if (! mb_check_encoding($data, 'UTF-8')) {
            // a cut in the middle of a character is fine, anything else is taken as Latin-1
            $trim = rtrim($data, "\x80..\xBF");
            $data = mb_check_encoding(substr($trim, 0, -1), 'UTF-8') ? substr($trim, 0, -1) : mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }

        return $data;
    }

    /** Pulls the readable text out of the XML parts of an Office / OpenDocument file. */
    private function office(string $path, array $parts, string $paragraphEnd, array $extra = []): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $out = '';
        foreach ($parts as $part) {
            $xml = $this->entry($zip, $part);
            if ($xml !== null) {
                $out .= $this->xmlToText($xml, $paragraphEnd, $extra)."\n";
            }
        }
        $zip->close();

        return $out;
    }

    private function pptx(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^ppt/(slides|notesSlides)/(?:slide|notesSlide)(\d+)\.xml$#', (string) $zip->getNameIndex($i), $m)) {
                $slides[(int) $m[2]][$m[1]] = $zip->getNameIndex($i);
            }
        }
        ksort($slides);
        $out = '';
        foreach ($slides as $n => $parts) {
            $out .= "--- Slide $n ---\n";
            foreach ($parts as $kind => $part) {
                $xml = $this->entry($zip, $part);
                $out .= ($kind === 'notesSlides' ? 'Notes: ' : '').trim($this->xmlToText((string) $xml, '</a:p>'))."\n";
            }
        }
        $zip->close();

        return $out;
    }

    private function xlsx(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }

        $shared = [];
        if (($xml = $this->entry($zip, 'xl/sharedStrings.xml')) !== null && ($sx = $this->xml($xml))) {
            foreach ($sx->si as $si) {
                $shared[] = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('</t>', ' </t>', (string) $si->asXML()))));
            }
        }

        $names = [];
        if (($xml = $this->entry($zip, 'xl/workbook.xml')) !== null && ($wb = $this->xml($xml))) {
            foreach ($wb->sheets->sheet ?? [] as $i => $sheet) {
                $names[] = (string) $sheet['name'];
            }
        }

        $out = '';
        $sheet = 0;
        while (($xml = $this->entry($zip, 'xl/worksheets/sheet'.(++$sheet).'.xml')) !== null) {
            $out .= '--- Sheet: '.($names[$sheet - 1] ?? $sheet)." ---\n";
            if (! ($sx = $this->xml($xml))) {
                continue;
            }
            foreach ($sx->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $type = (string) $c['t'];
                    $v = (string) $c->v;
                    $cells[] = match ($type) {
                        's' => $shared[(int) $v] ?? '',
                        'inlineStr' => trim(strip_tags((string) $c->is->asXML())),
                        default => $v,
                    };
                }
                if (array_filter($cells, fn ($x) => $x !== '')) {
                    $out .= implode("\t", $cells)."\n";
                }
                if (strlen($out) > self::FILE_CHARS * 4) {
                    break 2;
                }
            }
        }
        $zip->close();

        return $out;
    }

    /** The text files inside a zip, each under its path. */
    private function zip(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && ! str_ends_with($stat['name'], '/') && ! preg_match(self::ZIP_SKIP, $stat['name'])) {
                $names[] = $stat;
            }
        }
        $out = "Contents of the zip:\n".implode("\n", array_map(fn ($s) => '- '.$s['name'].' ('.$s['size'].' bytes)', array_slice($names, 0, 200)))."\n";

        $read = 0;
        foreach ($names as $s) {
            if ($read >= self::ZIP_FILES) {
                break;
            }
            if ($s['size'] > 0 && $s['size'] <= 400 * 1024 && ($data = $zip->getFromIndex($s['index'])) !== false && ($text = $this->plain($data, $s['size'])) !== null) {
                $out .= "\n=== {$s['name']} ===\n$text\n";
                $read++;
            }
        }
        $zip->close();

        return $out;
    }

    private function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if (! $stat || $stat['size'] > self::XML_BYTES) {
            return null;
        }
        $data = $zip->getFromName($name);

        return $data === false ? null : $data;
    }

    private function xml(string $xml): ?\SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($prev);

        return $x ?: null;
    }

    private function xmlToText(string $xml, string $paragraphEnd, array $extra = []): string
    {
        $xml = str_replace([$paragraphEnd, ...array_keys($extra)], ["$paragraphEnd\n", ...array_map(fn ($k) => $extra[$k], array_keys($extra))], $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
