<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Models\IssueDraft;
use App\Services\AttachmentUploader;
use App\Services\SimilarIssues;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DraftController extends Controller
{
    use ScopesToUser;

    private const LISTS = ['type' => 'types', 'priority' => 'priorities', 'subsystem' => 'subsystems', 'sprint' => 'sprints'];

    public function update(Request $request, int $conversation, YouTrackClient $yt)
    {
        $draft = $this->draft($request, $conversation);

        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'project' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'max:60'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'sprint' => ['nullable', 'string', 'max:120'],
            'subsystem' => ['nullable', 'string', 'max:120'],
            'labels' => ['nullable', 'array', 'max:20'],
            'labels.*' => ['string', 'max:60'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:255'],
        ]);

        $project = array_key_exists('project', $data) ? $data['project'] : $draft->project;
        if ($project && YouTrackClient::configured()) {
            try {
                $meta = $yt->meta($project);
            } catch (TrackerException $e) {
                return $e->response();
            }
            foreach (self::LISTS as $field => $key) {
                $value = array_key_exists($field, $data) ? $data[$field] : $draft->{$field};
                if (filled($value) && ! in_array($value, $meta[$key], true)) {
                    if (array_key_exists($field, $data)) {
                        throw ValidationException::withMessages([$field => __('ui.errors.invalid_option')]);
                    }
                    $data[$field] = null; // value from a previous project that this one does not offer
                }
            }
            $assignee = array_key_exists('assignee', $data) ? $data['assignee'] : $draft->assignee;
            if (filled($assignee) && ! in_array($assignee, array_column($meta['assignees'], 'value'), true)) {
                if (array_key_exists('assignee', $data)) {
                    throw ValidationException::withMessages(['assignee' => __('ui.errors.invalid_option')]);
                }
                $data['assignee'] = null;
            }
            if (array_key_exists('project', $data) && $data['project'] !== $draft->project) {
                $valid = array_column($meta['custom'], 'values', 'key');
                $data['custom_fields'] = array_filter(
                    $data['custom_fields'] ?? $draft->custom_fields ?? [],
                    fn ($v, $k) => isset($valid[$k]) && in_array($v, $valid[$k], true),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
        }

        $draft->fill($data);
        if ($draft->isDirty()) {
            $draft->revision++;
            if ($draft->status === 'error') {
                $draft->status = 'draft';
                $draft->error_message = null;
            }
            $draft->save();
        }

        return Presenter::draft($draft);
    }

    /** Look-alike issues for the draft (the live card text may not be saved yet, so the request can override it). */
    public function similar(Request $request, int $conversation, SimilarIssues $similar)
    {
        $draft = $this->draft($request, $conversation);
        $q = $request->validate(['summary' => ['nullable', 'string', 'max:255'], 'project' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string', 'max:3000']]);
        if (filled($draft->issue_key)) {
            return ['issues' => []];
        }

        return ['issues' => $similar->find($q['project'] ?? $draft->project, $q['summary'] ?? $draft->summary, $q['description'] ?? $draft->description)];
    }

    /** Explicit, user-confirmed creation in YouTrack (or update of the issue created from this draft). */
    public function create(Request $request, int $conversation, YouTrackClient $yt, AttachmentUploader $uploader)
    {
        $draft = $this->draft($request, $conversation);
        $request->validate(['confirmed' => ['accepted'], 'direct' => ['sometimes', 'boolean']]);

        if ($missing = $draft->missingFields()) {
            return response()->json(['message' => __('ui.errors.missing_fields'), 'missing' => $missing], 422);
        }
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }

        $isUpdate = filled($draft->issue_key);
        $fields = $draft->only([...IssueDraft::EDITABLE, 'labels', 'custom_fields']);
        $fields['type'] = $draft->type;

        try {
            if ($isUpdate) {
                $issue = $yt->update($draft->issue_key, $fields);
                $result = ['id' => $draft->issue_key, 'url' => $draft->issue_url, 'summary' => $issue['summary']];
            } else {
                $result = $yt->create($fields) + ['summary' => $yt->withPrefix($draft->summary)];
            }
        } catch (TrackerException $e) {
            $draft->update(['status' => 'error', 'error_message' => $e->getMessage()]);

            return $e->response(['draft' => Presenter::draft($draft->fresh())]);
        }

        $draft->update([
            'status' => 'created', 'issue_key' => $result['id'], 'issue_url' => $result['url'],
            'summary' => $result['summary'], 'error_message' => null,
        ]);

        $conv = $draft->conversation;
        $files = $uploader->upload($yt, $result['id'], $conv->attachments()->where('to_issue', true)->whereNull('youtrack_issue')->orderBy('id')->get());
        $message = $conv->messages()->create([
            'role' => 'assistant', 'kind' => 'creation-result', 'body' => null,
            'meta' => [
                'key' => $result['id'], 'url' => $result['url'], 'updated' => $isUpdate, 'summary' => $result['summary'],
                'direct' => $request->boolean('direct'),
                'attachments' => $files['sent'],
                'attachments_failed' => $files['failed'],
                // read-only snapshot of what was sent to YouTrack
                'preview' => $draft->only(['project', 'type', 'priority', 'assignee', 'subsystem', 'sprint', 'labels', 'custom_fields', 'description']),
            ],
        ]);
        $conv->touch();

        return ['draft' => Presenter::draft($draft->fresh()), 'message' => Presenter::message($message->load('attachments')), 'conversation' => Presenter::conversation($conv->fresh())];
    }

    /** Throws the draft away (nothing was created in the tracker); an issue that already exists is not undone from here. */
    public function destroy(Request $request, int $conversation)
    {
        $draft = $this->draft($request, $conversation);
        abort_if($draft->status === 'created', 409);
        $draft->delete();

        return response()->noContent();
    }

    private function draft(Request $request, int $conversation): IssueDraft
    {
        return $this->conversation($request, $conversation)->draft ?? abort(404);
    }
}
