<?php

namespace App\Services\YouTrack;

use App\Exceptions\TrackerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTrack REST client authenticated with a permanent token (server side only).
 * Normalised shapes:
 *  project = ['key','name','id']
 *  meta    = ['types','priorities','subsystems','sprints' => string[], 'assignees' => [['value'=>login,'label'=>name]],
 *             'custom' => [['key','label','values'=>string[]]]]
 */
class YouTrackClient
{
    private const ISSUE_FIELDS = 'id,idReadable,summary,description,updated,project(shortName,name),customFields(name,value(name,login,fullName))';

    /** Fields handled explicitly; every other enum-like project field is exposed as a custom field. */
    private const KNOWN = ['Type', 'Priority', 'Subsystem', 'Assignee', 'State'];

    public static function configured(): bool
    {
        return filled(config('services.youtrack.token')) && filled(config('services.youtrack.url'));
    }

    public function projects(): array
    {
        return Cache::remember('yt.projects', 120, function () {
            $rows = $this->get('/api/admin/projects', ['fields' => 'id,shortName,name,archived', '$top' => 200]);

            return collect($rows)->reject(fn ($p) => $p['archived'] ?? false)
                ->map(fn ($p) => ['key' => $p['shortName'], 'name' => $p['name'], 'id' => $p['id']])->values()->all();
        });
    }

    public function meta(string $projectKey): array
    {
        return Cache::remember("yt.meta.$projectKey", 120, function () use ($projectKey) {
            $id = $this->projectId($projectKey);
            $fields = $this->get("/api/admin/projects/$id/customFields", [
                'fields' => 'field(name),bundle(values(name)),$type',
                '$top' => 100,
            ]);

            $meta = ['types' => [], 'priorities' => [], 'subsystems' => [], 'states' => [], 'sprints' => [], 'assignees' => [], 'custom' => []];
            $map = ['Type' => 'types', 'Priority' => 'priorities', 'Subsystem' => 'subsystems'];

            foreach ($fields as $f) {
                $name = $f['field']['name'] ?? null;
                $values = collect($f['bundle']['values'] ?? [])->pluck('name')->filter()->values()->all();
                if ($name === 'State') {
                    $meta['states'] = $values;
                } elseif (isset($map[$name])) {
                    $meta[$map[$name]] = $values;
                } elseif ($name && ! in_array($name, self::KNOWN, true) && $values && str_starts_with($f['$type'] ?? '', 'Enum')) {
                    $meta['custom'][] = ['key' => $name, 'label' => $name, 'values' => $values];
                }
            }
            $meta['assignees'] = $this->assignees($id);
            $meta['sprints'] = array_column($this->sprints($projectKey), 'name');

            return $meta;
        });
    }

    /**
     * Builds a YouTrack search query from the filter form (values are quoted so spaces and dashes are safe).
     *
     * @param  array{q?:?string,project?:?string,type?:?string,priority?:?string,state?:?string,assignee?:?string}  $f
     */
    public function buildQuery(array $f): string
    {
        $quote = fn (string $v) => '{'.str_replace(['{', '}'], '', $v).'}';
        $parts = [];
        foreach (['project' => 'project', 'type' => 'Type', 'priority' => 'Priority', 'state' => 'State', 'assignee' => 'for'] as $key => $field) {
            if (filled($f[$key] ?? null)) {
                $parts[] = "$field: ".$quote($f[$key]);
            }
        }
        if (filled($f['q'] ?? null)) {
            $parts[] = trim($f['q']);
        }
        $query = implode(' ', $parts);

        return str_contains(strtolower($query), 'sort by') ? $query : trim($query.' sort by: updated desc');
    }

    /**
     * One page of issues (newest first). `total` is null when YouTrack has not finished counting.
     *
     * @return array{issues:list<array<string,mixed>>,has_more:bool,total:?int,page:int,per_page:int}
     */
    public function search(string $query, int $page = 1, int $perPage = 50, bool $withTotal = true): array
    {
        $skip = ($page - 1) * $perPage;
        // ask for one extra row to know whether another page exists
        $rows = $this->get('/api/issues', ['query' => $query, 'fields' => self::ISSUE_FIELDS, '$top' => $perPage + 1, '$skip' => $skip]);

        return [
            'issues' => array_map($this->normalize(...), array_slice($rows, 0, $perPage)),
            'has_more' => count($rows) > $perPage,
            'total' => $withTotal ? $this->count($query) : null,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** Total issues for a query. YouTrack computes it asynchronously (-1 while pending), so retry briefly. */
    public function count(string $query): ?int
    {
        return Cache::remember('yt.count.'.md5($query), 60, function () use ($query) {
            try {
                for ($i = 0; $i < 4; $i++) {
                    $r = $this->send('post', '/api/issuesGetter/count', ['fields' => 'count'], ['query' => $query]);
                    if (($r['count'] ?? -1) >= 0) {
                        return (int) $r['count'];
                    }
                    usleep(250_000);
                }
            } catch (TrackerException) {
                // the list still works without a total
            }

            return null;
        });
    }

    public function getIssue(string $id): array
    {
        return $this->normalize($this->get("/api/issues/$id", ['fields' => self::ISSUE_FIELDS]));
    }

    /** @param array<string,mixed> $draft summary, description, project, type, priority, assignee, labels, sprint, subsystem, custom_fields */
    public function create(array $draft): array
    {
        $values = [
            'Type' => $draft['type'] ?? null,
            'Priority' => $draft['priority'] ?? null,
            'Assignee' => $draft['assignee'] ?? null,
            'Subsystem' => $draft['subsystem'] ?? null,
        ] + ($draft['custom_fields'] ?? []);

        $created = $this->send('post', '/api/issues', ['fields' => 'id,idReadable'], [
            'project' => ['id' => $this->projectId($draft['project'])],
            'summary' => $this->withPrefix($draft['summary']),
            'description' => $draft['description'] ?? '',
            'customFields' => $this->customFieldsPayload($draft['project'], $values),
        ]);

        foreach ($draft['labels'] ?? [] as $label) {
            $this->tryAddTag($created['id'], $label);
        }
        if (filled($draft['sprint'] ?? null)) {
            $this->tryAddToSprint($created['id'], $draft['project'], $draft['sprint']);
        }

        return ['id' => $created['idReadable'], 'url' => config('services.youtrack.url').'/issue/'.$created['idReadable']];
    }

    public function update(string $id, array $patch): array
    {
        $body = array_filter([
            'summary' => isset($patch['summary']) ? $this->summaryForUpdate($id, $patch['summary']) : null,
            'description' => $patch['description'] ?? null,
        ], fn ($v) => $v !== null);

        $project = explode('-', $id)[0];
        $custom = $this->customFieldsPayload($project, [
            'Type' => $patch['type'] ?? null,
            'Priority' => $patch['priority'] ?? null,
            'Assignee' => $patch['assignee'] ?? null,
            'Subsystem' => $patch['subsystem'] ?? null,
            'State' => $patch['state'] ?? null,
        ] + ($patch['custom_fields'] ?? []));
        if ($custom) {
            $body['customFields'] = $custom;
        }

        $this->send('post', "/api/issues/$id", ['fields' => 'id'], $body);

        return $this->getIssue($id);
    }

    /** Issues created by the app carry the title prefix: keep it when their title is edited (others are left alone). */
    private function summaryForUpdate(string $id, string $summary): string
    {
        $prefix = (string) config('services.youtrack.title_prefix');
        if ($prefix === '') {
            return $summary;
        }

        return str_contains($this->getIssue($id)['summary'], $prefix) ? $this->withPrefix($summary) : $summary;
    }

    public function withPrefix(string $summary): string
    {
        $prefix = (string) config('services.youtrack.title_prefix');

        return $prefix === '' || str_contains($summary, $prefix) ? $summary : "$prefix - $summary";
    }

    // ---- internals ------------------------------------------------------

    private function projectId(string $key): string
    {
        foreach ($this->projects() as $p) {
            if ($p['key'] === $key) {
                return $p['id'];
            }
        }
        throw new TrackerException(__('ui.errors.unknown_project', ['project' => $key]), 404);
    }

    /** @param array<string,?string> $values */
    private function customFieldsPayload(string $projectKey, array $values): array
    {
        $id = $this->projectId($projectKey);
        $defs = collect($this->get("/api/admin/projects/$id/customFields", ['fields' => 'field(name,fieldType(isMultiValue)),$type', '$top' => 100]))
            ->mapWithKeys(fn ($f) => [$f['field']['name'] => ['type' => $f['$type'] ?? '', 'multi' => (bool) ($f['field']['fieldType']['isMultiValue'] ?? false)]]);

        $out = [];
        foreach ($values as $name => $value) {
            if (blank($value) || ! $defs->has($name)) {
                continue;
            }
            ['type' => $t, 'multi' => $multi] = $defs[$name];
            $kind = match (true) {
                str_starts_with($t, 'User') => 'User',
                str_starts_with($t, 'Owned') => 'Owned',
                str_starts_with($t, 'Enum') => 'Enum',
                str_starts_with($t, 'State') => 'State',
                str_starts_with($t, 'Text') => 'Text',
                default => null,
            };
            if ($kind === null) {
                continue; // period/date/simple fields are not handled by the draft
            }

            // multi-valued fields (e.g. an Assignee that accepts several users) need Multi* types and a list
            $single = match ($kind) {
                'User' => ['login' => $value],
                'Text' => ['text' => $value],
                default => ['name' => $value],
            };
            $out[] = $multi && in_array($kind, ['User', 'Owned', 'Enum'], true)
                ? ['name' => $name, '$type' => "Multi{$kind}IssueCustomField", 'value' => [$single]]
                : ['name' => $name, '$type' => match ($kind) {
                    'State' => 'StateIssueCustomField', 'Text' => 'TextIssueCustomField', default => "Single{$kind}IssueCustomField",
                }, 'value' => $single];
        }

        return $out;
    }

    private function assignees(string $projectId): array
    {
        try {
            $rows = $this->get("/api/admin/projects/$projectId/team/users", ['fields' => 'login,fullName', '$top' => 200]);
        } catch (TrackerException) {
            return [];
        }

        return collect($rows)->map(fn ($u) => ['value' => $u['login'], 'label' => $u['fullName'] ?? $u['login']])->values()->all();
    }

    /** @return list<array{name:string,id:string,agile:string}> */
    private function sprints(string $projectKey): array
    {
        try {
            $boards = $this->get('/api/agiles', ['fields' => 'id,projects(shortName),sprints(id,name,archived)', '$top' => 50]);
        } catch (TrackerException) {
            return [];
        }

        return collect($boards)
            ->filter(fn ($b) => collect($b['projects'] ?? [])->contains('shortName', $projectKey))
            ->flatMap(fn ($b) => collect($b['sprints'] ?? [])->reject(fn ($s) => $s['archived'] ?? false)
                ->map(fn ($s) => ['name' => $s['name'], 'id' => $s['id'], 'agile' => $b['id']]))
            ->values()->all();
    }

    private function tryAddToSprint(string $issueId, string $projectKey, string $sprintName): void
    {
        try {
            foreach ($this->sprints($projectKey) as $s) {
                if ($s['name'] === $sprintName) {
                    $this->send('post', "/api/agiles/{$s['agile']}/sprints/{$s['id']}/issues", ['fields' => 'id'], ['id' => $issueId]);

                    return;
                }
            }
        } catch (TrackerException $e) {
            Log::warning('YouTrack: could not add issue to sprint', ['message' => $e->getMessage()]);
        }
    }

    private function tryAddTag(string $issueId, string $label): void
    {
        try {
            $existing = $this->get('/api/tags', ['fields' => 'id,name', 'query' => $label, '$top' => 20]);
            $tag = collect($existing)->first(fn ($t) => strcasecmp($t['name'], $label) === 0)
                ?? $this->send('post', '/api/tags', ['fields' => 'id,name'], ['name' => $label]);
            $this->send('post', "/api/issues/$issueId/tags", ['fields' => 'id'], ['id' => $tag['id']]);
        } catch (TrackerException $e) {
            Log::warning('YouTrack: could not add tag', ['message' => $e->getMessage()]); // best effort
        }
    }

    private function normalize(array $i): array
    {
        $cf = collect($i['customFields'] ?? [])->mapWithKeys(fn ($f) => [
            $f['name'] => is_array($f['value'] ?? null) ? ($f['value']['fullName'] ?? $f['value']['name'] ?? null) : null,
        ]);
        $readable = $i['idReadable'] ?? $i['id'];

        return [
            'id' => $readable,
            'summary' => $i['summary'] ?? '',
            'description' => $i['description'] ?? '',
            'project' => $i['project']['shortName'] ?? null,
            'type' => $cf['Type'] ?? null,
            'priority' => $cf['Priority'] ?? null,
            'assignee' => $cf['Assignee'] ?? null,
            'state' => $cf['State'] ?? null,
            'url' => config('services.youtrack.url').'/issue/'.$readable,
            'updated' => isset($i['updated']) ? date('c', intdiv($i['updated'], 1000)) : null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::withToken(config('services.youtrack.token'))->acceptJson()->timeout(20)
            ->baseUrl(config('services.youtrack.url'));
    }

    private function get(string $path, array $query = []): array
    {
        return $this->handle(fn () => $this->http()->get($path, $query));
    }

    private function send(string $method, string $path, array $query, array $body): array
    {
        return $this->handle(fn () => $this->http()->withQueryParameters($query)->{$method}($path, $body));
    }

    private function handle(callable $call): array
    {
        try {
            $r = $call();
        } catch (ConnectionException) {
            throw new TrackerException(__('ui.errors.tracker_unreachable'));
        }

        if ($r->failed()) {
            Log::warning('YouTrack API error', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 500)]);
            $msg = match (true) {
                in_array($r->status(), [401, 403], true) => __('ui.errors.tracker_auth'),
                $r->status() === 404 => __('ui.errors.issue_not_found'),
                $r->status() === 400 => (string) ($r->json('error_description') ?? __('ui.errors.tracker_error', ['status' => 400])),
                default => __('ui.errors.tracker_error', ['status' => $r->status()]),
            };
            throw new TrackerException($msg, $r->status());
        }

        return $r->json() ?? [];
    }
}
