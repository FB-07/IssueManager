<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Services\ClaudePrompt;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stateless proxy to the Anthropic Messages API (streaming). The API key never reaches the browser.
 * Events (SSE): text {t}, draft {patch}, create {issue}, update {issue}, error {message}, done {}.
 */
class ChatController extends Controller
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const TEXT_EXT = ['txt', 'md', 'log', 'json', 'csv', 'php', 'js', 'ts', 'css', 'html', 'vue', 'sql', 'yml', 'yaml'];

    public function __invoke(Request $request)
    {
        $cfg = config('services.anthropic');
        if (blank($cfg['key'])) {
            return response()->json(['message' => __('ui.errors.chat_not_configured')], 503);
        }

        $payload = json_decode((string) $request->input('payload'), true);
        $v = Validator::make(['p' => $payload, 'files' => $request->file('files', [])], [
            'p' => ['required', 'array'],
            'p.messages' => ['required', 'array', 'min:1', 'max:80'],
            'p.messages.*.role' => ['required', 'in:user,assistant'],
            'p.messages.*.content' => ['required', 'string', 'max:30000'],
            'p.draft_enabled' => ['boolean'],
            'p.draft' => ['nullable', 'array'],
            'p.catalog' => ['nullable', 'array'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'max:10240'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $draftOn = (bool) ($payload['draft_enabled'] ?? false);
        $messages = $this->buildMessages($payload['messages'], $request->file('files', []));

        $body = [
            'model' => $cfg['model'],
            'max_tokens' => $cfg['max_tokens'],
            'stream' => true,
            'system' => $draftOn
                ? ClaudePrompt::withDraft($payload['draft'] ?? null, $payload['catalog'] ?? [])
                : ClaudePrompt::plain($payload['catalog'] ?? [], $payload['draft'] ?? null),
            'messages' => $messages,
        ];
        $yt = YouTrackClient::configured() ? app(YouTrackClient::class) : null;
        $body['tools'] = array_values(array_filter([
            $draftOn ? ClaudePrompt::draftTool() : ClaudePrompt::createTool(),
            $yt ? ClaudePrompt::updateIssueTool() : null,
            $yt ? ClaudePrompt::findIssuesTool() : null,
            $yt ? ClaudePrompt::getIssueTool() : null,
        ]));

        return new StreamedResponse(fn () => $this->relay($cfg, $body, $yt), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param list<UploadedFile> $files */
    private function buildMessages(array $history, array $files): array
    {
        $out = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $history);

        $last = array_key_last($out);
        if ($out[$last]['role'] !== 'user') {
            return $out;
        }

        $blocks = [];
        $notes = [];
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $ext = strtolower($file->getClientOriginalExtension());
            $mime = (string) $file->getMimeType();

            if (in_array($mime, self::IMAGE_MIMES, true) && $file->getSize() <= 5 * 1024 * 1024) {
                $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($file->get())]];
            } elseif ($mime === 'application/pdf') {
                $blocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($file->get())]];
            } elseif (in_array($ext, self::TEXT_EXT, true) && $file->getSize() <= 200 * 1024) {
                $blocks[] = ['type' => 'text', 'text' => "File \"$name\":\n```\n".$file->get()."\n```"];
            } else {
                $notes[] = $name;
            }
        }

        $text = $out[$last]['content'];
        if ($notes) {
            $text .= "\n\n[Attached but not readable by the assistant: ".implode(', ', $notes).']';
        }
        $blocks[] = ['type' => 'text', 'text' => $text];
        $out[$last]['content'] = $blocks;

        return $out;
    }

    /**
     * Streams the model's answer. Tools the app applies itself (draft, create, update) become SSE events; the lookup
     * tools (find_issues / get_issue) are run here and the model continues with their result (a few rounds at most).
     */
    private function relay(array $cfg, array $body, ?YouTrackClient $yt): void
    {
        $emit = function (string $event, array $data) {
            echo "event: $event\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };

        try {
            for ($round = 0; $round < 5; $round++) {
                $result = $this->streamRound($cfg, $body, $emit);
                if ($result === null) {
                    return; // an error event was already sent
                }

                $lookups = array_filter($result['blocks'], fn ($b) => $b['type'] === 'tool_use' && in_array($b['name'], ClaudePrompt::SERVER_TOOLS, true));
                if ($result['stop'] !== 'tool_use' || ! $lookups || ! $yt) {
                    break;
                }

                // every tool_use needs an answer: lookups get their data, the app-applied tools just an acknowledgement
                $answers = [];
                foreach ($result['blocks'] as $b) {
                    if ($b['type'] === 'tool_use') {
                        $answers[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $b['id'],
                            'content' => in_array($b['name'], ClaudePrompt::SERVER_TOOLS, true) ? $this->runLookup($yt, $b['name'], (array) $b['input']) : 'Handled by the app.',
                        ];
                    }
                }
                $body['messages'][] = ['role' => 'assistant', 'content' => $result['blocks']];
                $body['messages'][] = ['role' => 'user', 'content' => $answers];
            }
            $emit('done', []);
        } catch (\Throwable $e) {
            Log::error('Claude relay failed', ['message' => $e->getMessage()]);
            $emit('error', ['message' => __('ui.errors.chat_failed')]);
        }
    }

    /**
     * One streamed request to the model.
     *
     * @return array{blocks:list<array<string,mixed>>,stop:?string}|null null after an error event
     */
    private function streamRound(array $cfg, array $body, callable $emit): ?array
    {
        $response = Http::withHeaders(['x-api-key' => $cfg['key'], 'anthropic-version' => $cfg['version']])
            ->withOptions(['stream' => true])->timeout(180)->post($cfg['url'], $body);

        $stream = $response->toPsrResponse()->getBody();

        if ($response->failed()) {
            $err = json_decode((string) $stream, true);
            Log::warning('Claude API error', ['status' => $response->status(), 'error' => $err['error'] ?? null]);
            $emit('error', ['message' => $this->friendly($response->status(), $err['error']['message'] ?? null)]);

            return null;
        }

        $state = ['blocks' => [], 'stop' => null, 'failed' => false];
        $buffer = '';
        while (! $stream->eof()) {
            $buffer .= $stream->read(1024);
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $raw = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $this->handleEvent($raw, $state, $emit);
            }
        }

        return $state['failed'] ? null : [
            'blocks' => array_values(array_filter($state['blocks'], fn ($b) => $b['type'] !== 'text' || $b['text'] !== '')),
            'stop' => $state['stop'],
        ];
    }

    private function handleEvent(string $raw, array &$state, callable $emit): void
    {
        $data = null;
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
            }
        }
        if (! is_array($data)) {
            return;
        }

        $i = $data['index'] ?? null;

        switch ($data['type'] ?? '') {
            case 'content_block_start':
                $cb = $data['content_block'] ?? [];
                $state['blocks'][$i] = ($cb['type'] ?? '') === 'tool_use'
                    ? ['type' => 'tool_use', 'id' => $cb['id'] ?? 'toolu_'.uniqid(), 'name' => $cb['name'] ?? '', 'input' => [], '_json' => '']
                    : ['type' => 'text', 'text' => ''];
                break;
            case 'content_block_delta':
                $d = $data['delta'] ?? [];
                if (($d['type'] ?? '') === 'text_delta' && isset($state['blocks'][$i])) {
                    $state['blocks'][$i]['text'] .= $d['text'];
                    $emit('text', ['t' => $d['text']]);
                } elseif (($d['type'] ?? '') === 'input_json_delta' && isset($state['blocks'][$i])) {
                    $state['blocks'][$i]['_json'] .= $d['partial_json'];
                }
                break;
            case 'content_block_stop':
                if (isset($state['blocks'][$i]) && $state['blocks'][$i]['type'] === 'tool_use') {
                    $block = &$state['blocks'][$i];
                    $input = json_decode($block['_json'] ?: '{}', true);
                    $block['input'] = is_array($input) && $input ? $input : (object) [];
                    unset($block['_json']);

                    $event = ['update_issue_draft' => 'draft', 'create_issue' => 'create', 'update_issue' => 'update'][$block['name']] ?? null;
                    if ($event && is_array($input)) {
                        $emit($event, $event === 'draft' ? ['patch' => $input] : ['issue' => $input]);
                    }
                    unset($block);
                }
                break;
            case 'message_delta':
                $state['stop'] = $data['delta']['stop_reason'] ?? $state['stop'];
                break;
            case 'error':
                Log::warning('Claude stream error', ['error' => $data['error'] ?? null]);
                $emit('error', ['message' => $this->friendly(null, $data['error']['message'] ?? null)]);
                $state['failed'] = true;
                break;
        }
    }

    /** Result of find_issues / get_issue as a compact JSON string for the model. */
    private function runLookup(YouTrackClient $yt, string $name, array $input): string
    {
        try {
            if ($name === 'find_issues') {
                $found = $yt->search($yt->buildQuery(['q' => (string) ($input['query'] ?? '')]), 1, 8, false)['issues'];

                return json_encode(array_map(fn ($i) => array_intersect_key($i, array_flip(['id', 'summary', 'project', 'type', 'priority', 'assignee', 'state'])), $found), JSON_UNESCAPED_UNICODE);
            }

            $issue = $yt->getIssue((string) ($input['issue_id'] ?? ''));
            $issue['description'] = mb_substr((string) $issue['description'], 0, 8000);

            return json_encode(array_intersect_key($issue, array_flip(['id', 'summary', 'description', 'project', 'type', 'priority', 'assignee', 'state'])), JSON_UNESCAPED_UNICODE);
        } catch (TrackerException $e) {
            return 'Error: '.$e->getMessage();
        }
    }

    private function friendly(?int $status, ?string $detail): string
    {
        return match (true) {
            $status === 401 => __('ui.errors.chat_auth'),
            $status === 429 => __('ui.errors.chat_rate'),
            default => __('ui.errors.chat_failed'),
        };
    }
}
