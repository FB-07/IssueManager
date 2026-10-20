<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Models\IssueDraft;
use App\Models\Conversation;
use App\Services\AttachmentUploader;
use App\Services\DraftPatch;
use App\Services\SimilarIssues;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;

/**
 * Several issues proposed from one pasted text. The proposals live in the assistant message's `meta.batch.items`;
 * nothing reaches YouTrack until the user approves items here (with whatever edits they made in the review list).
 * Item status: pending -> created | discarded; a failed attempt stays pending with an `error`.
 */
class BatchController extends Controller
{
    use ScopesToUser;

    public const MAX_ITEMS = 15;

    private const FIELDS = ['summary', 'description', 'project', 'type', 'priority', 'assignee', 'subsystem', 'labels', 'custom_fields'];

    /**
     * Never trust the model: each proposal keeps only known fields with values that exist in its own project.
     *
     * @param  array<int,mixed>  $raw
     * @return list<array<string,mixed>>
     *
     * @throws TrackerException
     */
    public static function sanitizeProposals(array $raw, YouTrackClient $yt): array
    {
        $items = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_ITEMS) as $proposal) {
            $patch = is_array($proposal) ? DraftPatch::sanitize($proposal, null, $yt) : null;
            if ($patch && filled($patch['summary'] ?? null)) {
                $items[] = $patch + ['status' => 'pending'];
            }
        }

        return $items;
    }

    public function __invoke(Request $request, int $conversation, int $message, YouTrackClient $yt, AttachmentUploader $uploader)
    {
        $c = $this->conversation($request, $conversation);
        $m = $c->messages()->whereKey($message)->firstOrFail();
        $meta = $m->meta ?? [];
        $items = $meta['batch']['items'] ?? abort(404);

        $data = $request->validate([
            'create' => ['array', 'max:'.self::MAX_ITEMS],
            'create.*.index' => ['required', 'integer', 'min:0'],
            'create.*.summary' => ['nullable', 'string', 'max:255'],
            'create.*.description' => ['nullable', 'string', 'max:50000'],
            'create.*.project' => ['nullable', 'string', 'max:50'],
            'create.*.type' => ['nullable', 'string', 'max:60'],
            'create.*.priority' => ['nullable', 'string', 'max:60'],
            'create.*.assignee' => ['nullable', 'string', 'max:120'],
            'create.*.subsystem' => ['nullable', 'string', 'max:120'],
            'create.*.labels' => ['nullable', 'array', 'max:20'],
            'create.*.custom_fields' => ['nullable', 'array'],
            'create.*.attachments' => ['nullable', 'array', 'max:10'],
            'create.*.attachments.*' => ['integer'],
            'discard' => ['array', 'max:'.self::MAX_ITEMS],
            'discard.*' => ['integer', 'min:0'],
        ]);
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }

        foreach ($data['discard'] ?? [] as $i) {
            if (($items[$i]['status'] ?? null) === 'pending') {
                $items[$i]['status'] = 'discarded';
            }
        }

        $files = [];
        foreach ($data['create'] ?? [] as $edit) {
            $i = $edit['index'];
            if (($items[$i]['status'] ?? null) !== 'pending') {
                continue;
            }

            try {
                $item = $this->merge($items[$i], $edit, $yt, $c);
                if ($missing = array_values(array_filter(IssueDraft::REQUIRED, fn ($f) => blank($item[$f] ?? null)))) {
                    $items[$i] = ['error' => __('ui.errors.missing_fields').' ('.implode(', ', $missing).')', 'missing' => $missing] + $item;

                    continue;
                }
                $result = $yt->create($item);
                // the files ticked for this proposal go to the new issue (one failing file never undoes the issue)
                $files = $uploader->upload($yt, $result['id'], $c->attachments()->whereIn('id', $item['attachments'] ?? [])->orderBy('id')->get(), mark: false);
                $items[$i] = [
                    'status' => 'created', 'issue_key' => $result['id'], 'issue_url' => $result['url'],
                    'attachments_sent' => $files['sent'], 'attachments_failed' => $files['failed'],
                ] + array_diff_key($item, ['error' => 1, 'missing' => 1]);
            } catch (TrackerException $e) {
                $items[$i] = ['error' => $e->getMessage()] + $items[$i];
            }
        }

        $meta['batch']['items'] = array_values($items);
        $m->update(['meta' => $meta]);
        $c->touch();

        return ['message' => Presenter::message($m->fresh()->load('attachments'))];
    }

    /**
     * Keeps the review in progress: the edits made to the pending proposals and which ones were approved or rejected, so a
     * reload (or another device) picks up where the user left off. Nothing is created here.
     */
    public function save(Request $request, int $conversation, int $message, YouTrackClient $yt)
    {
        $c = $this->conversation($request, $conversation);
        $m = $c->messages()->whereKey($message)->firstOrFail();
        $meta = $m->meta ?? [];
        $items = $meta['batch']['items'] ?? abort(404);

        $data = $request->validate([
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.index' => ['required', 'integer', 'min:0'],
            'items.*.summary' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:50000'],
            'items.*.project' => ['nullable', 'string', 'max:50'],
            'items.*.type' => ['nullable', 'string', 'max:60'],
            'items.*.priority' => ['nullable', 'string', 'max:60'],
            'items.*.assignee' => ['nullable', 'string', 'max:120'],
            'items.*.subsystem' => ['nullable', 'string', 'max:120'],
            'items.*.labels' => ['nullable', 'array', 'max:20'],
            'items.*.custom_fields' => ['nullable', 'array'],
            'items.*.attachments' => ['nullable', 'array', 'max:10'],
            'items.*.attachments.*' => ['integer'],
            'items.*.decision' => ['nullable', 'in:accepted,rejected'],
        ]);
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }

        foreach ($data['items'] as $edit) {
            $i = $edit['index'];
            if (($items[$i]['status'] ?? null) !== 'pending') {
                continue; // created or discarded proposals are final
            }

            try {
                $item = $this->merge($items[$i], $edit, $yt, $c);
            } catch (TrackerException $e) {
                return $e->response();
            }
            $item['decision'] = $edit['decision'] ?? null;
            if (isset($items[$i]['error']) && $item['decision'] === 'accepted') {
                $item['error'] = $items[$i]['error']; // a failed creation keeps its message until the user changes their mind
            }
            $items[$i] = $item;
        }

        $meta['batch']['items'] = array_values($items);
        $m->update(['meta' => $meta]);

        return ['saved' => true];
    }

    /** Look-alikes of one proposal, from the text as currently edited in its card (nothing is saved until the batch is created). */
    public function similar(Request $request, int $conversation, int $message, int $index, SimilarIssues $similar)
    {
        $m = $this->conversation($request, $conversation)->messages()->whereKey($message)->firstOrFail();
        $item = $m->meta['batch']['items'][$index] ?? abort(404);
        $q = $request->validate(['summary' => ['nullable', 'string', 'max:255'], 'project' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string', 'max:3000']]);
        if (($item['status'] ?? null) !== 'pending') {
            return ['issues' => []];
        }

        return ['issues' => $similar->find($q['project'] ?? $item['project'] ?? null, $q['summary'] ?? $item['summary'] ?? null, $q['description'] ?? $item['description'] ?? null)];
    }

    /** The stored proposal with the user's edits applied (only values valid for the project survive). */
    private function merge(array $stored, array $edit, YouTrackClient $yt, Conversation $c): array
    {
        // files of this conversation only (an id from another conversation is simply dropped)
        $attachments = array_key_exists('attachments', $edit)
            ? $c->attachments()->whereIn('id', $edit['attachments'] ?? [])->orderBy('id')->pluck('id')->all()
            : ($stored['attachments'] ?? []);

        $edit = array_intersect_key($edit, array_flip(self::FIELDS));
        $patch = DraftPatch::sanitize($edit, $stored['project'] ?? null, $yt) ?? [];

        // fields the user cleared on purpose (empty string, or an emptied list) must not come back from the stored proposal
        $cleared = array_keys(array_filter($edit, fn ($v) => $v === null || $v === '' || $v === []));
        $cleared = array_values(array_diff($cleared, ['labels'])); // an empty label list is already what sanitize returns
        $base = array_diff_key($stored, array_flip($cleared));

        if (($patch['project'] ?? $base['project'] ?? null) !== ($base['project'] ?? null)) {
            // another project has other lists: do not carry over values that belong to the old one
            $base = array_diff_key($base, array_flip(['type', 'priority', 'assignee', 'subsystem', 'custom_fields']));
        }

        return ['attachments' => $attachments] + array_diff_key($patch + $base, ['error' => 1, 'missing' => 1, 'attachments' => 1]);
    }
}
