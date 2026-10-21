<?php

use App\Services\AttachmentReader;
use Illuminate\Http\UploadedFile;

function zipFile(string $name, array $entries): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $z = new ZipArchive;
    $z->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $content) {
        $z->addFromString($entry, $content);
    }
    $z->close();

    return new UploadedFile($path, $name, null, null, true);
}

function readFiles(UploadedFile ...$files): array
{
    return (new AttachmentReader)->read($files);
}

it('reads any text file by content, whatever the extension', function () {
    $r = readFiles(UploadedFile::fake()->createWithContent('app.config', "key=válor\n"), UploadedFile::fake()->createWithContent('x.rs', 'fn main() {}'));

    expect($r['notes'])->toBe([])
        ->and($r['blocks'][0]['text'])->toContain('key=válor')
        ->and($r['blocks'][1]['text'])->toContain('fn main');
});

it('reads latin-1 text', function () {
    $r = readFiles(UploadedFile::fake()->createWithContent('a.txt', mb_convert_encoding('ação', 'ISO-8859-1', 'UTF-8')));

    expect($r['blocks'][0]['text'])->toContain('ação');
});

it('extracts the text of a docx', function () {
    $docx = zipFile('a.docx', ['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Olá</w:t></w:r></w:p><w:p><w:r><w:t>Mundo &amp; Co</w:t></w:r></w:p></w:body></w:document>']);
    $text = readFiles($docx)['blocks'][0]['text'];

    expect($text)->toContain("Olá\nMundo & Co");
});

it('extracts the cells of an xlsx', function () {
    $xlsx = zipFile('a.xlsx', [
        'xl/workbook.xml' => '<workbook><sheets><sheet name="Dados" sheetId="1"/></sheets></workbook>',
        'xl/sharedStrings.xml' => '<sst><si><t>Nome</t></si><si><t>Ana</t></si></sst>',
        'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="s"><v>0</v></c><c><v>Idade</v></c></row><row><c t="s"><v>1</v></c><c><v>31</v></c></row></sheetData></worksheet>',
    ]);
    $text = readFiles($xlsx)['blocks'][0]['text'];

    expect($text)->toContain('Sheet: Dados')->toContain("Nome\tIdade")->toContain("Ana\t31");
});

it('lists a zip and reads its text files, skipping binaries', function () {
    $zip = zipFile('a.zip', ['src/a.php' => '<?php echo 1;', 'img.bin' => "\0\1\2\3"]);
    $text = readFiles($zip)['blocks'][0]['text'];

    expect($text)->toContain('src/a.php')->toContain('img.bin')->toContain('echo 1;');
});

it('says why a file could not be read', function () {
    $r = readFiles(UploadedFile::fake()->createWithContent('old.doc', "\0\0binary"), UploadedFile::fake()->createWithContent('b.dat', "\0\0\0"));

    expect($r['blocks'])->toBe([])->and($r['notes'])->toHaveCount(2);
});

it('shrinks an image above 5 MB so the model can take it', function () {
    $img = imagecreatetruecolor(2000, 2000);
    for ($i = 0; $i < 2000; $i += 3) {
        imageline($img, 0, $i, 2000, 2000 - $i, random_int(0, 0xFFFFFF));
    }
    $path = tempnam(sys_get_temp_dir(), 'img');
    imagebmp($img, $path, false);
    expect(filesize($path))->toBeGreaterThan(5 * 1024 * 1024);

    $r = readFiles(new UploadedFile($path, 'big.bmp', 'image/bmp', null, true));

    expect($r['notes'])->toBe([])->and($r['blocks'][0]['source']['media_type'])->toBe('image/jpeg');
});

it('refuses programs and accepts big files in the chat endpoint', function () {
    expect(AttachmentReader::MAX_KB)->toBe(25600);
    $fail = null;
    AttachmentReader::allowed('files.0', UploadedFile::fake()->create('virus.exe', 1), function ($m) use (&$fail) {
        $fail = $m;
    });
    expect($fail)->not->toBeNull();
});
