<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\DraftPatch;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists one chat turn (the user message + the reply that came from the chat API) and applies the draft patch. */
class TurnController extends Controller
{
    use ScopesToUser;

    private const MIMES = 'png,jpg,jpeg,gif,webp,pdf,txt,md,log,json,csv,zip,doc,docx,xls,xlsx,php,js,ts,css,html,vue,sql,yml,yaml';

    public function __invoke(Request $request, int $conversation, YouTrackClient $yt)
    {
        $c = $this->conversation($request, $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'assistant' => ['required', 'string', 'max:100000'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:'.self::MIMES],
        ]);
        $reply = json_decode($data['assistant'], true);
        if (! is_array($reply) || blank($reply['text'] ?? null)) {
            return response()->json(['message' => __('ui.errors.chat_failed')], 422);
        }
        $text = trim($data['body'] ?? '');
        if ($text === '' && ! $request->hasFile('files')) {
            return response()->json(['message' => __('ui.errors.empty_message')], 422);
        }

        try {
            $patch = YouTrackClient::configured()
                ? DraftPatch::sanitize($reply['draft_patch'] ?? null, $c->draft?->project, $yt)
                : null;
        } catch (TrackerException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return DB::transaction(function () use ($request, $c, $text, $reply, $patch) {
            $user = $c->messages()->create(['role' => 'user', 'kind' => 'text', 'body' => $text]);

            foreach ($request->file('files', []) as $file) {
                $mime = (string) $file->getMimeType();
                $user->attachments()->create([
                    'conversation_id' => $c->id,
                    'kind' => in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? 'image' : 'file',
                    'name' => Str::limit($file->getClientOriginalName(), 200, ''),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                    'path' => $file->store("attachments/{$c->id}", 'local'),
                ]);
            }

            $draft = $c->draft;
            if ($patch) {
                $draft ??= $c->draft()->make(['revision' => 0, 'status' => 'draft']);
                $cf = $patch['custom_fields'] ?? null;
                unset($patch['custom_fields']);
                $draft->fill($patch);
                if ($cf) {
                    $draft->custom_fields = [...($draft->custom_fields ?? []), ...$cf];
                }
                if (! $draft->exists || $draft->isDirty()) {
                    $draft->revision++;
                    $draft->save();
                }
                $c->setRelation('draft', $draft);
            }

            // `direct` = draft mode off and the user explicitly asked for an issue: no editable card when the draft is
            // complete (the app creates it right away); an incomplete draft still shows the card so it can be filled in
            $direct = $patch && ! empty($reply['direct']) && $draft->missingFields() === [];

            $assistant = $c->messages()->create([
                'role' => 'assistant',
                'kind' => 'text',
                'body' => $reply['text'],
                'meta' => array_filter([
                    'card' => $patch && ! $direct ? true : null,
                    'direct' => $direct ?: null,
                    'revision' => $patch && ! $direct ? $draft->revision : null,
                    'asked_assignee' => ! empty($reply['asked_assignee']) ? true : null,
                ]),
            ]);

            if (blank($c->title)) {
                $c->title = Str::limit($draft?->summary ?: ($text ?: ($user->attachments->first()?->name ?? 'Nova conversa')), 48, '…');
            }
            $c->touch();
            $c->save();

            $user->load('attachments');

            return [
                'conversation' => Presenter::conversation($c),
                'messages' => [Presenter::message($user), Presenter::message($assistant->load('attachments'))],
                'draft' => Presenter::draft($draft),
            ];
        });
    }
}
