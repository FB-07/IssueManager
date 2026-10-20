<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Http\Controllers\Concerns\ScopesToUser;
use App\Models\IssueDraft;
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

    public function __invoke(Request $request, int $conversation, int $message, YouTrackClient $yt)
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
                $item = $this->merge($items[$i], $edit, $yt);
                if ($missing = array_values(array_filter(IssueDraft::REQUIRED, fn ($f) => blank($item[$f] ?? null)))) {
                    $items[$i] = ['error' => __('ui.errors.missing_fields').' ('.implode(', ', $missing).')', 'missing' => $missing] + $item;

                    continue;
                }
                $result = $yt->create($item);
                $items[$i] = ['status' => 'created', 'issue_key' => $result['id'], 'issue_url' => $result['url']] + array_diff_key($item, ['error' => 1, 'missing' => 1]);
            } catch (TrackerException $e) {
                $items[$i] = ['error' => $e->getMessage()] + $items[$i];
            }
        }

        $meta['batch']['items'] = array_values($items);
        $m->update(['meta' => $meta]);
        $c->touch();

        return ['message' => Presenter::message($m->fresh()->load('attachments'))];
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
    private function merge(array $stored, array $edit, YouTrackClient $yt): array
    {
        $edit = array_intersect_key($edit, array_flip(self::FIELDS));
        $patch = DraftPatch::sanitize($edit, $stored['project'] ?? null, $yt) ?? [];

        // fields the user cleared on purpose (empty string) must not come back from the stored proposal
        $cleared = array_keys(array_filter($edit, fn ($v) => $v === null || $v === ''));
        $base = array_diff_key($stored, array_flip($cleared));

        if (($patch['project'] ?? $base['project'] ?? null) !== ($base['project'] ?? null)) {
            // another project has other lists: do not carry over values that belong to the old one
            $base = array_diff_key($base, array_flip(['type', 'priority', 'assignee', 'subsystem', 'custom_fields']));
        }

        return array_diff_key($patch + $base, ['error' => 1, 'missing' => 1]);
    }
}
