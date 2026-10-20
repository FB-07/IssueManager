<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\DraftPatch;
use App\Services\QuickReplies;
use App\Services\SimilarIssues;
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

    public function __invoke(Request $request, int $conversation, YouTrackClient $yt, SimilarIssues $similar)
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
            return $e->response();
        }

        $batch = [];
        if (YouTrackClient::configured() && is_array($reply['batch'] ?? null)) {
            try {
                $batch = BatchController::sanitizeProposals($reply['batch'], $yt);
            } catch (TrackerException $e) {
                return $e->response();
            }
        }
        if ($batch) {
            $patch = null; // a batch replaces the single draft for this turn
        }

        return DB::transaction(function () use ($request, $c, $text, $reply, $patch, $batch, $similar) {
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

            // no confirmation window in this mode, so an issue that looks like an existing one must not be created behind the
            // user's back: the editable card is shown instead (with the look-alikes) and the user decides
            $found = $direct && blank($draft->issue_key) ? $similar->find($draft->project, $draft->summary, $draft->description) : [];
            if ($found) {
                $direct = false;
                $reply['text'] = rtrim($reply['text'])."\n\n".__(collect($found)->contains('duplicate', true) ? 'ui.chat.duplicate_held' : 'ui.chat.similar_held');
            }

            // like the single issue card, every file sent in the conversation (this message's or an earlier one's) is ticked
            // for each proposal; the user unticks what a given issue should not carry
            $fromFiles = $batch ? $c->attachments()->orderBy('id')->pluck('id')->all() : [];
            $batch = array_map(fn ($item) => $item + ['attachments' => $fromFiles], $batch);

            $assistant = $c->messages()->create([
                'role' => 'assistant',
                'kind' => 'text',
                'body' => $reply['text'],
                'meta' => array_filter([
                    'card' => $patch && ! $direct ? true : null,
                    'direct' => $direct ?: null,
                    'revision' => $patch && ! $direct ? $draft->revision : null,
                    'batch' => $batch ? ['items' => $batch] : null,
                    'asked_assignee' => ! empty($reply['asked_assignee']) ? true : null,
                    'replies' => QuickReplies::sanitize($reply['replies'] ?? [], allowActions: true) ?: null,
                ]),
            ]);

            if (blank($c->title)) {
                $c->title = Str::limit($draft?->summary ?: ($batch[0]['summary'] ?? null) ?: ($text ?: ($user->attachments->first()?->name ?? 'Nova conversa')), 48, '…');
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
