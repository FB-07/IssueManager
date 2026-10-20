<?php

namespace App\Services\YouTrack;

use App\Exceptions\TrackerException;
use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Auth;
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
    /** Without the (possibly long) description: for lists of many issues. */
    private const LIST_FIELDS = 'id,idReadable,summary,updated,created,resolved,reporter(login,fullName),project(shortName,name),customFields(name,value(name,login,fullName))';

    private const ISSUE_FIELDS = 'id,idReadable,summary,description,updated,created,resolved,reporter(login,fullName),project(shortName,name),customFields(name,value(name,login,fullName))';

    /** Fields handled explicitly; every other enum-like project field is exposed as a custom field. */
    private const KNOWN = ['Type', 'Priority', 'Subsystem', 'Assignee', 'State'];

    /**
     * Each logged-in user calls YouTrack with their own Hub token; the shared token is only a local development fallback.
     * `$user` is only passed explicitly during the login callback; otherwise the user is looked up on every call, so a
     * client kept alive across requests (cached controller instance, Octane…) can never act as someone else.
     */
    public function __construct(private readonly ?User $user = null, private readonly ?HubOAuth $oauth = null) {}

    private function user(): ?User
    {
        return $this->user ?? Auth::user();
    }

    /** True when a YouTrack URL and a way to authenticate (Hub login or the shared dev token) are configured. */
    public static function configured(): bool
    {
        return filled(config('services.youtrack.url'))
            && (filled(config('services.youtrack.token')) || filled(config('services.youtrack.client_id')));
    }

    /** Token of the current user (refreshed when about to expire). @throws TrackerException status 401 when there is none */
    private function token(): string
    {
        $conn = $this->user()?->youtrackConnection;

        if ($conn) {
            if ($conn->expires_at && $conn->expires_at->subMinute()->isPast()) {
                $conn = ($this->oauth ?? app(HubOAuth::class))->refresh($conn);
            }

            return $conn->access_token;
        }

        // only the local "dev login" account (no Hub token) may use the shared one, and only in local development
        if (app()->isLocal() && config('services.youtrack.dev_login') && filled(config('services.youtrack.token'))
            && $this->user()?->email === AuthController::DEV_EMAIL) {
            return (string) config('services.youtrack.token');
        }

        throw new TrackerException(__('ui.errors.session_expired'), 401);
    }

    /** Caches are per user: what a person can see depends on their permissions. */
    private function cacheKey(string $name): string
    {
        return 'yt.'.($this->user()?->id ?? 'guest').'.'.$name;
    }

    /** Cheap call used right after login to check the account really has access to YouTrack. */
    public function me(): array
    {
        return $this->get('/api/users/me', ['fields' => 'id,login,fullName']);
    }

    public function projects(): array
    {
        return Cache::remember($this->cacheKey('projects'), 120, function () {
            $rows = $this->get('/api/admin/projects', ['fields' => 'id,shortName,name,archived,iconUrl', '$top' => 200]);

            return collect($rows)->reject(fn ($p) => $p['archived'] ?? false)
                ->map(fn ($p) => ['key' => $p['shortName'], 'name' => $p['name'], 'id' => $p['id'], 'icon' => $this->imageUrl($p['iconUrl'] ?? null)])->values()->all();
        });
    }

    public function meta(string $projectKey): array
    {
        return Cache::remember($this->cacheKey("meta.$projectKey"), 120, function () use ($projectKey) {
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
    public function search(string $query, int $page = 1, int $perPage = 50, bool $withTotal = true, bool $withDescription = true): array
    {
        $skip = ($page - 1) * $perPage;
        // ask for one extra row to know whether another page exists
        $rows = $this->get('/api/issues', ['query' => $query, 'fields' => $withDescription ? self::ISSUE_FIELDS : self::LIST_FIELDS, '$top' => $perPage + 1, '$skip' => $skip]);

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
        return Cache::remember($this->cacheKey('count.'.md5($query)), 60, function () use ($query) {
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

    private const STOPWORDS = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'not', 'are', 'was', 'can', 'une', 'uma', 'para', 'com', 'que', 'por', 'dos', 'das', 'nos', 'nas', 'não', 'nao', 'mais', 'como', 'ser', 'erro', 'issue'];

    /**
     * Open-ended look-alikes for a draft title, from its keywords, in the same project.
     *
     * @return list<array<string,mixed>>
     */
    public function similar(string $project, string $summary, int $limit = 5, ?string $description = null): array
    {
        $words = $this->keywords($summary);
        $found = [];
        $add = function (string $query) use (&$found, $limit) {
            $rows = Cache::remember($this->cacheKey('similar.'.md5($query.$limit)), 60, fn () => $this->search($query, 1, $limit, false)['issues']);
            foreach ($rows as $row) {
                $found[$row['id']] ??= $row;
            }

            return $rows !== [];
        };

        // Every word of a long title rarely appears in an older issue about the same thing, so besides all the words (up to 6)
        // also search with only its 3 and its 2 longest ones; the caller scores the candidates and drops the unrelated ones.
        $byLength = $words;
        usort($byLength, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $queries = $words ? [implode(' ', array_slice($words, 0, 6))] : [];
        foreach ([3, 2] as $n) {
            if (count($words) > $n) {
                $queries[] = implode(' ', array_slice($byLength, 0, $n));
            }
        }
        foreach (array_unique($queries) as $q) {
            $add($this->buildQuery(['project' => $project, 'q' => $q]));
        }

        // an older issue about the same thing may be titled differently: also look for the draft's longest description words,
        // loosening (4, then 3 words that must all appear) because the same idea is rarely worded identically
        $fromText = $this->keywords((string) $description);
        if (count($fromText) >= 5) {
            usort($fromText, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach ([4, 3] as $n) {
                if ($add($this->buildQuery(['project' => $project, 'q' => implode(' ', array_slice($fromText, 0, $n))]))) {
                    break;
                }
            }
        }

        return array_values($found);
    }

    /** @return list<string> distinctive words of a text (3+ letters, no stop words), in order of appearance */
    private function keywords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->stripPrefix($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))));
    }

    private function stripPrefix(string $summary): string
    {
        $prefix = config('services.youtrack.title_prefix');

        return $prefix && str_starts_with($summary, $prefix) ? substr($summary, strlen($prefix)) : $summary;
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

    /** Uploads one file to an issue (`$key` is the readable id, e.g. DEV-12). */
    public function attach(string $key, string $name, string $mime, string $contents): void
    {
        try {
            $r = Http::withToken($this->token())->acceptJson()->timeout(60)->baseUrl(config('services.youtrack.url'))
                ->attach('file', $contents, $name, ['Content-Type' => $mime ?: 'application/octet-stream'])
                ->post("/api/issues/$key/attachments?fields=id,name");
        } catch (ConnectionException) {
            throw new TrackerException(__('ui.errors.tracker_unreachable'));
        }

        if ($r->failed()) {
            Log::warning('YouTrack attachment upload failed', ['status' => $r->status(), 'issue' => $key, 'name' => $name]);
            throw new TrackerException(__('ui.errors.tracker_error', ['status' => $r->status()]), $r->status());
        }
    }

    /** Avatars and project icons are only served for these YouTrack paths (never an arbitrary URL). */
    private const IMAGE_PATH = '#^/(hub/api/rest/avatar/[A-Za-z0-9-]+|api/entityIcons/[A-Za-z0-9_.-]+)(\?[A-Za-z0-9=&%._+\-]*)?$#';

    /** URL of the app's own image proxy for a YouTrack avatar/icon path (the browser has no YouTrack token). */
    public function imageUrl(?string $path): ?string
    {
        return $path && preg_match(self::IMAGE_PATH, $path) ? '/api/youtrack/image?path='.rawurlencode($path) : null;
    }

    /**
     * Fetches an avatar/icon with the current user's token.
     *
     * @return array{body:string,type:string}
     *
     * @throws TrackerException
     */
    public function fetchImage(string $path): array
    {
        if (! preg_match(self::IMAGE_PATH, $path)) {
            throw new TrackerException(__('ui.errors.issue_not_found'), 404);
        }

        try {
            $r = Http::withToken($this->token())->timeout(15)->baseUrl(config('services.youtrack.url'))->get($path);
        } catch (ConnectionException) {
            throw new TrackerException(__('ui.errors.tracker_unreachable'));
        }

        $type = strtolower(trim(explode(';', (string) $r->header('Content-Type'))[0]));
        if ($r->failed() || ! str_starts_with($type, 'image/') || strlen($r->body()) > 1_000_000) {
            throw new TrackerException(__('ui.errors.issue_not_found'), 404);
        }

        return ['body' => $r->body(), 'type' => $type];
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
            $rows = $this->get("/api/admin/projects/$projectId/team/users", ['fields' => 'login,fullName,avatarUrl', '$top' => 200]);
        } catch (TrackerException) {
            return [];
        }

        return collect($rows)->map(fn ($u) => ['value' => $u['login'], 'label' => $u['fullName'] ?? $u['login'], 'avatar' => $this->imageUrl($u['avatarUrl'] ?? null)])->values()->all();
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

    /**
     * A custom field's value as text. YouTrack sends one value as an object and a multi-value field (Assignee is often one) as a
     * list of objects: both are read, a list joined with ", ".
     */
    private function fieldValue(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }
        $one = fn ($v) => is_array($v) ? ($v['fullName'] ?? $v['name'] ?? $v['login'] ?? null) : null;
        if (array_is_list($value)) {
            $names = array_values(array_filter(array_map($one, $value)));

            return $names ? implode(', ', $names) : null;
        }

        return $one($value);
    }

    private function normalize(array $i): array
    {
        $cf = collect($i['customFields'] ?? [])->mapWithKeys(fn ($f) => [$f['name'] => $this->fieldValue($f['value'] ?? null)]);
        $readable = $i['idReadable'] ?? $i['id'];
        $date = fn ($ms) => isset($ms) ? date('c', intdiv((int) $ms, 1000)) : null;

        return [
            'id' => $readable,
            'summary' => $i['summary'] ?? '',
            'description' => $i['description'] ?? '',
            'project' => $i['project']['shortName'] ?? null,
            'type' => $cf['Type'] ?? null,
            'priority' => $cf['Priority'] ?? null,
            'assignee' => $cf['Assignee'] ?? null,
            'reporter' => isset($i['reporter']) ? ($i['reporter']['fullName'] ?? $i['reporter']['login'] ?? null) : null, // who created it
            'state' => $cf['State'] ?? null,
            'url' => config('services.youtrack.url').'/issue/'.$readable,
            'created' => $date($i['created'] ?? null),
            'resolved' => $date($i['resolved'] ?? null),
            'updated' => $date($i['updated'] ?? null),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(20)
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
                $r->status() === 401 => __('ui.errors.session_expired'),
                $r->status() === 403 => __('ui.errors.tracker_auth'),
                $r->status() === 404 => __('ui.errors.issue_not_found'),
                $r->status() === 400 => (string) ($r->json('error_description') ?? __('ui.errors.tracker_error', ['status' => 400])),
                default => __('ui.errors.tracker_error', ['status' => $r->status()]),
            };
            throw new TrackerException($msg, $r->status());
        }

        return $r->json() ?? [];
    }
}
