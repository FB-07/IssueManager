<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\AttachmentUploader;
use App\Services\DraftPatch;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Applies an edit the user asked for in the chat to an existing YouTrack issue and records it in the conversation. */
class IssueEditController extends Controller
{
    use ScopesToUser;

    public function __invoke(Request $request, int $conversation, YouTrackClient $yt, AttachmentUploader $uploader)
    {
        $c = $this->conversation($request, $conversation);

        $data = $request->validate([
            'issue_id' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.]+(-[A-Za-z0-9_.]+)*-\d+$/'],
            'summary' => ['nullable', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:50000'],
            'type' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'max:60'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'subsystem' => ['nullable', 'string', 'max:120'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:255'],
        ]);
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }

        $key = $data['issue_id'];
        try {
            $patch = DraftPatch::sanitize($data, Str::beforeLast($key, '-'), $yt, withState: true);
            // images/files sent together with the request ("anexa esta imagem ao issue") go to the issue
            $pending = $c->messages()->where('role', 'user')->latest('id')->first()?->attachments()->where('to_issue', true)->whereNull('youtrack_issue')->get() ?? collect();
            if (! $patch && $pending->isEmpty()) {
                return response()->json(['message' => __('ui.errors.nothing_to_change')], 422);
            }
            $issue = $patch ? $yt->update($key, $patch) : $yt->getIssue($key);
        } catch (TrackerException $e) {
            return $e->response();
        }

        $files = $uploader->upload($yt, $key, $pending);

        // keep the editable draft in sync when the edited issue is the one created from this conversation
        $draft = $c->draft;
        if ($patch && $draft && $draft->issue_key === $key) {
            $cf = $patch['custom_fields'] ?? null;
            $draft->fill(array_intersect_key($patch, array_flip(['description', 'type', 'priority', 'assignee', 'subsystem'])) + ['summary' => $issue['summary']]);
            if ($cf) {
                $draft->custom_fields = [...($draft->custom_fields ?? []), ...$cf];
            }
            if ($draft->isDirty()) {
                $draft->revision++;
                $draft->save();
            }
        }

        $message = $c->messages()->create([
            'role' => 'assistant',
            'kind' => 'creation-result',
            'body' => null,
            'meta' => [
                'key' => $issue['id'], 'url' => $issue['url'], 'demo' => false, 'updated' => true, 'direct' => true,
                'summary' => $issue['summary'],
                'changes' => array_keys($patch ?? []),
                'attachments' => $files['sent'],
                'attachments_failed' => $files['failed'],
                'preview' => ['project' => $issue['project'], 'type' => $issue['type'], 'priority' => $issue['priority'],
                    'assignee' => $issue['assignee'], 'state' => $issue['state'], 'description' => $issue['description']],
            ],
        ]);
        $c->touch();

        return ['message' => Presenter::message($message->load('attachments')), 'draft' => Presenter::draft($draft?->fresh())];
    }
}
