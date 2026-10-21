<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Http\Request;

/** Thin JSON layer over YouTrackClient (the token never leaves the server). */
class YouTrackController extends Controller
{
    public function __construct(private readonly YouTrackClient $yt) {}

    public function catalog()
    {
        return $this->run(function () {
            $projects = array_slice($this->yt->projects(), 0, 15);
            $byProject = [];
            foreach ($projects as $p) {
                $byProject[$p['key']] = $this->yt->meta($p['key']);
            }

            return ['projects' => array_map(fn ($p) => ['key' => $p['key'], 'name' => $p['name'], 'icon' => $p['icon'] ?? null], $projects), 'byProject' => (object) $byProject];
        });
    }

    public function projects()
    {
        return $this->run(fn () => ['projects' => $this->yt->projects()]);
    }

    public function meta(string $project)
    {
        return $this->run(fn () => $this->yt->meta($project));
    }

    /** Avatar / project icon proxy: sent with the user's own token, locked to a few YouTrack paths. */
    public function image(Request $request)
    {
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:400']])['path'];

        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }
        try {
            $img = $this->yt->fetchImage($path);
        } catch (TrackerException $e) {
            return $e->response();
        }

        return response($img['body'], 200, [
            'Content-Type' => $img['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // an SVG opened directly can never run script
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function issues(Request $request)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'project' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'max:60'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'per_page' => ['nullable', 'integer', 'in:12,24,48,25,50,100'],
        ]);
        // sorted by project (then newest first): the page shows one block per project
        $f['q'] = trim(($f['q'] ?? '').' sort by: project asc, updated desc');

        return $this->run(function () use ($f) {
            $result = $this->yt->search($this->yt->buildQuery($f), (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 12));

            return $result + $this->projectInfo($result, '', $f);
        });
    }

    /** The signed-in user's own issues: assigned to them or created by them, open or resolved (YouTrack resolves "me" by the token). */
    public function mine(Request $request)
    {
        $f = $request->validate([
            'scope' => ['nullable', 'in:assigned,created'],
            'status' => ['nullable', 'in:open,resolved,all'],
            'q' => ['nullable', 'string', 'max:200'],
            'project' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'per_page' => ['nullable', 'integer', 'in:12,24,48'],
        ]);
        // "mine" (for: me / reporter: me, open or resolved) plus the same filters as the list of existing issues
        // sorted by project (then newest first) so the page can show one block per project
        $f['q'] = trim(($f['q'] ?? '').' sort by: project asc, updated desc');
        $query = $this->mineQuery($f['scope'] ?? 'assigned', $f['status'] ?? 'open').' '.$this->yt->buildQuery($f);

        return $this->run(function () use ($f, $query) {
            $result = $this->yt->search($query, (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 12));

            return $result + $this->projectInfo($result, $this->mineQuery($f['scope'] ?? 'assigned', $f['status'] ?? 'open'), $f);
        });
    }

    /**
     * What the page shows next to each project name: how many issues it has in all (with the same filters), and which of
     * them are on this page ("48-51 of 50"). Projects come one after the other, so every project after the first starts at
     * 0; the first one starts after the issues of the projects that sort before it (the sort is by project name).
     *
     * @param  array{issues:list<array<string,mixed>>,page:int,per_page:int}  $result
     * @param  string  $prefix  extra query terms ("for: me #Unresolved" for "my issues")
     * @return array{project_totals:array<string,?int>,project_ranges:array<string,array{0:int,1:int}>}
     */
    private function projectInfo(array $result, string $prefix, array $f): array
    {
        $issues = $result['issues'];
        $keys = array_values(array_unique(array_filter(array_column($issues, 'project'))));
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = $this->yt->count(trim($prefix.' '.$this->yt->buildQuery([...$f, 'project' => $key])));
        }

        $ranges = [];
        $perProject = array_count_values(array_column($issues, 'project'));
        foreach ($keys as $i => $key) {
            $ranges[$key] = [0, $perProject[$key]];
        }
        if ($keys) {
            $offset = ($result['page'] - 1) * $result['per_page'];
            $first = $keys[0];
            $before = $offset;
            if ($offset > 0 && blank($f['project'] ?? null)) {
                $name = $issues[0]['project_name'] ?? null;
                $earlier = $name === null ? [] : array_values(array_filter($this->yt->projects(), fn ($p) => $p['key'] !== $first && strcmp($p['name'], $name) < 0));
                $before = 0;
                if ($earlier) {
                    $list = implode(', ', array_map(fn ($p) => '{'.str_replace(['{', '}'], '', $p['key']).'}', $earlier));
                    $before = $this->yt->count(trim($prefix.' project: '.$list.' '.$this->yt->buildQuery([...$f, 'project' => null]))) ?? $offset;
                }
            }
            $start = $offset - $before;
            // the guess about the order is only used when it makes sense: it cannot start before 0 or run past the project's total
            if ($start >= 0 && ($totals[$first] === null || $start + $perProject[$first] <= $totals[$first])) {
                $ranges[$first] = [$start, $start + $perProject[$first]];
            } else {
                unset($ranges[$first]);
            }
        }

        return ['project_totals' => $totals, 'project_ranges' => $ranges];
    }

    /** The numbers at the top of "your issues". */
    public function mineSummary()
    {
        return $this->run(fn () => [
            'assigned_open' => $this->yt->count($this->mineQuery('assigned', 'open')),
            'assigned_resolved' => $this->yt->count($this->mineQuery('assigned', 'resolved')),
            'created' => $this->yt->count($this->mineQuery('created', 'all')),
        ]);
    }

    private function mineQuery(string $scope, string $status): string
    {
        $parts = [$scope === 'created' ? 'reporter: me' : 'for: me'];
        if ($status !== 'all') {
            $parts[] = $status === 'open' ? '#Unresolved' : '#Resolved';
        }

        return implode(' ', $parts);
    }

    public function issue(string $id)
    {
        return $this->run(fn () => $this->yt->getIssue($id));
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'summary' => ['required', 'string', 'max:240'],
            'description' => ['required', 'string', 'max:50000'],
            'project' => ['required', 'string', 'max:50'],
            'type' => ['required', 'string', 'max:60'],
            'priority' => ['required', 'string', 'max:60'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'sprint' => ['nullable', 'string', 'max:120'],
            'subsystem' => ['nullable', 'string', 'max:120'],
            'labels' => ['nullable', 'array', 'max:20'],
            'labels.*' => ['string', 'max:60'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->run(fn () => $this->yt->create($data) + ['summary' => $this->yt->withPrefix($data['summary'])], 201);
    }

    public function update(Request $request, string $id)
    {
        $patch = $request->validate([
            'summary' => ['sometimes', 'string', 'max:240'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:60'],
            'state' => ['sometimes', 'nullable', 'string', 'max:60'],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subsystem' => ['sometimes', 'nullable', 'string', 'max:120'],
            'custom_fields' => ['sometimes', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->run(fn () => $this->yt->update($id, $patch));
    }

    private function run(callable $fn, int $status = 200)
    {
        if (! YouTrackClient::configured()) {
            return response()->json(['message' => __('ui.errors.tracker_not_configured')], 503);
        }
        try {
            return response()->json($fn(), $status);
        } catch (TrackerException $e) {
            return $e->response();
        }
    }
}
