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
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        return $this->run(fn () => $this->yt->search($this->yt->buildQuery($f), (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 25)));
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
