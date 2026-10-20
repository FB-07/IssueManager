<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Services\ClaudePrompt;
use App\Services\QuickReplies;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stateless proxy to the Anthropic Messages API (streaming). The API key never reaches the browser.
 * Events (SSE): text {t}, draft {patch}, create {issue}, update {issue}, batch {message, issues}, replies {replies}, error {message}, done {}.
 */
class ChatController extends Controller
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const MAX_USER_CHARS = 30000;

    private const MAX_ASSISTANT_CHARS = 400000;

    /** Earlier answers longer than this are cut when sent back to the model (it can look the data up again if it needs it). */
    private const KEEP_ASSISTANT_CHARS = 6000;

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
            // what the user types is limited; what the assistant wrote earlier may be very long (a list of hundreds of issues) and
            // is shortened below before it goes back to the model
            'p.messages.*.content' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($payload) {
                $role = $payload['messages'][(int) explode('.', $attribute)[2]]['role'] ?? 'user';
                if (mb_strlen((string) $value) > ($role === 'assistant' ? self::MAX_ASSISTANT_CHARS : self::MAX_USER_CHARS)) {
                    $fail(__('ui.errors.message_too_long', ['max' => $role === 'assistant' ? self::MAX_ASSISTANT_CHARS : self::MAX_USER_CHARS]));
                }
            }],
            'p.draft_enabled' => ['boolean'],
            'p.draft' => ['nullable', 'array'],
            'p.catalog' => ['nullable', 'array'],
            'p.context' => ['nullable', 'array'],
            'p.context.key' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.]+(-[A-Za-z0-9_.]+)*-\d+$/'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'max:10240'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $draftOn = (bool) ($payload['draft_enabled'] ?? false);
        $context = $this->contextIssue($payload['context']['key'] ?? null);
        $who = $this->whoIsTalking($request);
        $messages = $this->buildMessages($payload['messages'], $request->file('files', []));

        $body = [
            'model' => $cfg['model'],
            'max_tokens' => $cfg['max_tokens'],
            'stream' => true,
            'system' => $draftOn
                ? ClaudePrompt::withDraft($payload['draft'] ?? null, $payload['catalog'] ?? [], $context, $who)
                : ClaudePrompt::plain($payload['catalog'] ?? [], $payload['draft'] ?? null, $context, $who),
            'messages' => $messages,
        ];
        $yt = YouTrackClient::configured() ? app(YouTrackClient::class) : null;
        $body['tools'] = array_values(array_filter([
            $draftOn ? ClaudePrompt::draftTool() : ClaudePrompt::createTool(),
            ClaudePrompt::suggestRepliesTool(),
            $yt ? ClaudePrompt::updateIssueTool() : null,
            $yt ? ClaudePrompt::proposeIssuesTool() : null,
            $yt ? ClaudePrompt::findIssuesTool() : null,
            $yt ? ClaudePrompt::countIssuesTool() : null,
            $yt ? ClaudePrompt::getIssueTool() : null,
        ]));

        return new StreamedResponse(fn () => $this->relay($cfg, $body, $yt), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The YouTrack account behind this session (name and login as YouTrack knows them, cached for a while); if YouTrack cannot be
     * asked, the app's own record of the user. The local dev user acts as the owner of the shared token, so its fake email is left out.
     *
     * @return array{name:?string,login:?string,email:?string}
     */
    private function whoIsTalking(Request $request): array
    {
        $user = $request->user();
        $email = $user->email === AuthController::DEV_EMAIL ? null : $user->email;
        $me = [];

        if (YouTrackClient::configured()) {
            try {
                $me = Cache::remember('yt.'.$user->id.'.me', 600, fn () => app(YouTrackClient::class)->me());
            } catch (TrackerException) {
                $me = [];
            }
        }

        return [
            'name' => $me['fullName'] ?? ($user->email === AuthController::DEV_EMAIL ? null : $user->name),
            'login' => $me['login'] ?? null,
            'email' => $email,
        ];
    }

    /**
     * The chat's context issue with its fields as they are in YouTrack right now (it may have been changed there meanwhile).
     * If it cannot be read, Claude still gets the key, so "close it" still targets the right issue.
     *
     * @return ?array<string,mixed>
     */
    private function contextIssue(?string $key): ?array
    {
        if (blank($key)) {
            return null;
        }
        if (! YouTrackClient::configured()) {
            return ['key' => $key];
        }

        try {
            $issue = app(YouTrackClient::class)->getIssue($key);
            $issue['description'] = mb_substr((string) $issue['description'], 0, 3000);

            return ['key' => $key] + array_intersect_key($issue, array_flip(['summary', 'description', 'project', 'type', 'priority', 'assignee', 'state']));
        } catch (TrackerException) {
            return ['key' => $key];
        }
    }

    /**
     * A very long earlier answer (say, a list of 568 issues) would be sent again with every new message, costing context and
     * time for nothing: keep its beginning and end and say that the middle was left out.
     */
    private function shorten(string $text): string
    {
        $length = mb_strlen($text);
        if ($length <= self::KEEP_ASSISTANT_CHARS) {
            return $text;
        }
        $head = (int) (self::KEEP_ASSISTANT_CHARS * 0.75);
        $tail = self::KEEP_ASSISTANT_CHARS - $head;

        return mb_substr($text, 0, $head)."\n\n[… ".($length - self::KEEP_ASSISTANT_CHARS).' characters of this earlier answer were left out to save space; look the data up again if you need it …]'."\n\n".mb_substr($text, -$tail);
    }

    /** @param list<UploadedFile> $files */
    private function buildMessages(array $history, array $files): array
    {
        $out = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['role'] === 'assistant' ? $this->shorten($m['content']) : $m['content']], $history);

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
     * tools (find_issues / count_issues / get_issue) are run here and the model continues with their result (a few rounds at most).
     */
    private function relay(array $cfg, array $body, ?YouTrackClient $yt): void
    {
        // A long list takes many rounds (pages of YouTrack results, each followed by the model): the default 30 s of PHP would
        // kill the request, which the browser sees as a 502. Cap it at a few minutes instead.
        @set_time_limit(300);

        $emit = function (string $event, array $data) {
            $this->write("event: $event\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n");
        };

        try {
            for ($round = 0; $round < 8; $round++) {
                $result = $this->streamRound($cfg, $body, $emit);
                if ($result === null) {
                    return; // an error event was already sent
                }

                if ($result['stop'] === 'max_tokens') {
                    Log::warning('Claude answer cut off (max_tokens)', ['max_tokens' => $body['max_tokens']]);
                    $emit('error', ['message' => __('ui.errors.chat_truncated')]);

                    return;
                }

                $lookups = array_filter($result['blocks'], fn ($b) => $b['type'] === 'tool_use' && in_array($b['name'], ClaudePrompt::SERVER_TOOLS, true));
                $continues = $result['stop'] === 'tool_use' && $lookups && $yt && $round < 7;

                // The text of a round is held back until the round is over: if the model then goes to look something up, what it
                // said before ("Aqui está a lista:") is only narration and is dropped, so the user waits behind the dots and
                // gets the complete answer, not an intro followed by a long pause. The answer itself is sent once it is ready.
                if (! $continues && $result['text'] !== '') {
                    $emit('text', ['t' => $result['text']]);
                }
                if (! $continues) {
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

    private float $lastWrite = 0.0;

    private function write(string $chunk): void
    {
        echo $chunk;
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
        $this->lastWrite = microtime(true);
    }

    /** The answer is held back while the model works, so say "still here" now and then: proxies cut connections that stay silent. */
    private function keepAlive(): void
    {
        if (microtime(true) - $this->lastWrite > 5) {
            $this->write(": keep-alive\n\n");
        }
    }

    /**
     * One streamed request to the model.
     *
     * @return array{blocks:list<array<string,mixed>>,stop:?string,text:string}|null null after an error event; `text` is what the model wrote in this round (not sent yet)
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

        $state = ['blocks' => [], 'stop' => null, 'failed' => false, 'text' => []];
        $buffer = '';
        while (! $stream->eof()) {
            $buffer .= $stream->read(1024);
            $this->keepAlive();
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $raw = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $this->handleEvent($raw, $state, $emit);
            }
        }

        return $state['failed'] ? null : [
            'blocks' => array_values(array_filter($state['blocks'], fn ($b) => $b['type'] !== 'text' || $b['text'] !== '')),
            'stop' => $state['stop'],
            'text' => implode('', $state['text']),
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
                    $state['text'][] = $d['text']; // sent by relay() once the round is known to be the final one
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

                    if ($block['name'] === 'suggest_replies' && is_array($input)) {
                        if ($replies = QuickReplies::sanitize($input['replies'] ?? [])) {
                            $emit('replies', ['replies' => $replies]);
                        }
                    }

                    $event = ['update_issue_draft' => 'draft', 'create_issue' => 'create', 'update_issue' => 'update', 'propose_issues' => 'batch'][$block['name']] ?? null;
                    if ($event && is_array($input)) {
                        $emit($event, match ($event) {
                            'draft' => ['patch' => $input],
                            'batch' => ['message' => $input['message'] ?? '', 'issues' => array_values((array) ($input['issues'] ?? []))],
                            default => ['issue' => $input],
                        });
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

    /** Result of find_issues / count_issues / get_issue as a compact JSON string for the model. */
    private function runLookup(YouTrackClient $yt, string $name, array $input): string
    {
        try {
            if ($name === 'count_issues') {
                $query = $yt->buildQuery(['q' => (string) ($input['query'] ?? '')]);
                $total = $yt->count($query);

                return json_encode($total === null
                    ? ['error' => 'YouTrack could not count right now; try again or list the issues instead.']
                    : ['query' => (string) ($input['query'] ?? ''), 'total' => $total], JSON_UNESCAPED_UNICODE);
            }

            if ($name === 'find_issues') {
                $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
                $page = max(1, (int) ($input['page'] ?? 1));
                $res = $yt->search($yt->buildQuery(['q' => (string) ($input['query'] ?? '')]), $page, $limit, true, false);
                $columns = ['id', 'summary', 'project', 'type', 'priority', 'assignee', 'reporter', 'state', 'created', 'resolved'];

                return json_encode([
                    'total' => $res['total'], // all matching issues, not only this page (null if YouTrack could not count)
                    'page' => $page, 'shown' => count($res['issues']), 'has_more' => $res['has_more'],
                    'columns' => $columns, // each issue is a row in this order: shorter than repeating the names 200 times
                    'issues' => array_map(fn ($i) => array_map(fn ($c) => match ($c) {
                        'summary' => mb_substr((string) $i[$c], 0, 140),
                        'created', 'resolved' => $i[$c] ? substr($i[$c], 0, 10) : null, // the day is enough
                        default => $i[$c],
                    }, $columns), $res['issues']),
                ], JSON_UNESCAPED_UNICODE);
            }

            $issue = $yt->getIssue((string) ($input['issue_id'] ?? ''));
            $issue['description'] = mb_substr((string) $issue['description'], 0, 8000);

            return json_encode(array_intersect_key($issue, array_flip(['id', 'summary', 'description', 'project', 'type', 'priority', 'assignee', 'reporter', 'state', 'created', 'resolved'])), JSON_UNESCAPED_UNICODE);
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
