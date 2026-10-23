<?php

use App\Models\Conversation;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(yt_user());
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok', 'services.youtrack.title_prefix' => 'IssueTesteFabio']);
    Cache::flush();
});

it('creates an issue with the title prefix, mapped custom fields and the server-side token', function () {
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev', 'archived' => false]]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Assignee', 'fieldType' => ['isMultiValue' => true]], '$type' => 'UserProjectCustomField'],
        ]),
        'yt.test/api/issues*' => Http::response(['id' => '2-9', 'idReadable' => 'DEV-9']),
    ]);

    $res = $this->postJson('/api/youtrack/issues', [
        'summary' => 'Botão de pagar falha', 'description' => 'desc', 'project' => 'DEV',
        'type' => 'Anomaly', 'priority' => 'Major', 'assignee' => 'ana',
    ])->assertCreated()->assertJsonPath('id', 'DEV-9')->assertJsonPath('url', 'https://yt.test/issue/DEV-9')
        ->assertJsonPath('summary', 'IssueTesteFabio - Botão de pagar falha');

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/api/issues') || $r->method() !== 'POST') {
            return false;
        }
        $cf = collect($r['customFields'])->keyBy('name');

        return $r->hasHeader('Authorization', 'Bearer tok')
            && $r['summary'] === 'IssueTesteFabio - Botão de pagar falha'
            && $r['project']['id'] === '0-1'
            && $cf['Type']['value'] === ['name' => 'Anomaly']
            && $cf['Assignee']['$type'] === 'MultiUserIssueCustomField'
            && $cf['Assignee']['value'] === [['login' => 'ana']]
            && $cf['Type']['$type'] === 'SingleEnumIssueCustomField';
    });
});

it('does not duplicate the prefix', function () {
    expect(app(YouTrackClient::class)->withPrefix('IssueTesteFabio - x'))->toBe('IssueTesteFabio - x');
});

it('asks the user to log in again when YouTrack rejects their token, without leaking it', function () {
    Http::fake(['yt.test/*' => Http::response(['error' => 'Unauthorized'], 401)]);
    $this->getJson('/api/youtrack/projects')->assertStatus(401)->assertJsonPath('reauth', true)->assertJsonMissing(['tok']);
});

it('reports other YouTrack failures as a gateway error', function () {
    Http::fake(['yt.test/*' => Http::response(['error' => 'boom'], 500)]);
    $this->getJson('/api/youtrack/projects')->assertStatus(502)->assertJsonPath('reauth', false);
});

it('refuses tracker calls when YouTrack is not configured', function () {
    config(['services.youtrack.token' => null, 'services.youtrack.client_id' => null]);
    $this->getJson('/api/youtrack/projects')->assertStatus(503);
});

it('filters and paginates the issue search with the total, newest first', function () {
    $rows = fn (int $n) => array_map(fn ($i) => ['id' => "2-$i", 'idReadable' => "DEV-$i", 'summary' => "s$i", 'project' => ['shortName' => 'DEV'], 'customFields' => []], range(1, $n));
    Http::fake([
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 53]),
        'yt.test/api/issues?*' => Http::sequence()->push($rows(26))->push($rows(3)),
    ]);

    $this->getJson('/api/youtrack/issues?per_page=25&project=DEV&type=Show-stopper&assignee=ana.silva&q=pagamento')
        ->assertOk()->assertJsonCount(25, 'issues')->assertJsonPath('has_more', true)->assertJsonPath('total', 53)->assertJsonPath('page', 1);
    $this->getJson('/api/youtrack/issues?per_page=25&page=3')->assertOk()->assertJsonCount(3, 'issues')->assertJsonPath('has_more', false);

    $sent = Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), '/api/issues?'))->map(fn ($p) => urldecode($p[0]->url()))->values();
    expect($sent[0])->toContain('project: {DEV} Type: {Show-stopper} for: {ana.silva} pagamento sort by: project asc, updated desc')->toContain('$top=26')->toContain('$skip=0')
        ->and($sent[1])->toContain('$skip=50');

    $this->getJson('/api/youtrack/issues?per_page=500')->assertStatus(422);
    $this->getJson('/api/youtrack/issues?page=0')->assertStatus(422);
});

it('retries the total while YouTrack is still counting', function () {
    Http::fake(['yt.test/api/issuesGetter/count*' => Http::sequence()->push(['count' => -1])->push(['count' => -1])->push(['count' => 7])]);
    expect(app(YouTrackClient::class)->count('project: {DEV}'))->toBe(7);
});

it('gives no total when YouTrack never finishes counting', function () {
    Http::fake(['yt.test/api/issuesGetter/count*' => Http::response(['count' => -1])]);
    expect(app(YouTrackClient::class)->count('project: {OTHER}'))->toBeNull();
});

it('reports a total of zero instead of treating it as unknown', function () {
    Http::fake(['yt.test/api/issuesGetter/count*' => Http::response(['count' => 0])]);
    expect(app(YouTrackClient::class)->count('project: {EMPTY}'))->toBe(0);
});

it('lists look-alike issues for a draft title without failing the draft when YouTrack errors', function () {
    $conv = Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    $conv = $conv->id;
    $row = ['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'Erro no pagamento', 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    Http::fake(['yt.test/api/issues?*' => Http::sequence()->push([$row])->push([], 500)]);

    $this->getJson("/api/conversations/$conv/draft/similar?project=DEV&summary=Erro+no+pagamento+multibanco")
        ->assertOk()->assertJsonPath('issues.0.id', 'DEV-1');
    $this->getJson("/api/conversations/$conv/draft/similar?project=DEV&summary=")->assertOk()->assertJsonCount(0, 'issues');

    $this->getJson("/api/conversations/$conv/draft/similar?project=DEV&summary=outro+titulo")->assertOk()->assertJsonCount(0, 'issues');
});

it('flags look-alikes whose title is (nearly) the same as probable duplicates', function () {
    config(['services.youtrack.title_prefix' => 'IssueTesteFabio']);
    $conv = Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    $row = fn ($n, $title) => ['id' => "2-$n", 'idReadable' => "DEV-$n", 'summary' => $title, 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    Http::fake(['yt.test/api/issues?*' => Http::response([
        $row(1, 'IssueTesteFabio - Erro no pagamento multibanco'),
        $row(2, 'Pagamento multibanco com erro intermitente em produção'),
    ])]);

    $res = $this->getJson("/api/conversations/{$conv->id}/draft/similar?project=DEV&summary=Erro+no+pagamento+multibanco")->assertOk();

    $res->assertJsonPath('issues.0.duplicate', true)->assertJsonPath('issues.0.match', 100)
        ->assertJsonPath('issues.1.duplicate', false);
    expect($res->json('issues.1.match'))->toBeLessThan(80);
});

it('also flags a differently titled issue whose description is (nearly) the same, searches the description words too and drops unrelated ones', function () {
    $conv = Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    $text = 'Ao carregar no botão pagar com cartão multibanco o ecrã bloqueia e nunca confirma o pagamento do cliente';
    $row = fn ($n, $title, $desc) => ['id' => "2-$n", 'idReadable' => "DEV-$n", 'summary' => $title, 'description' => $desc, 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    Http::fake(['yt.test/api/issues?*' => Http::response([
        $row(1, 'Checkout bloqueado', 'Texto totalmente diferente sobre faturas, impostos e relatórios mensais do departamento financeiro'),
        $row(2, 'Pagamento multibanco bloqueado', $text.' em produção'),
    ])]);

    $res = $this->getJson("/api/conversations/{$conv->id}/draft/similar?".http_build_query(['project' => 'DEV', 'summary' => 'Pagamento multibanco bloqueia ecrã', 'description' => $text]))->assertOk();

    // besides the title words, the description's longest words were searched (here "carregar" only exists in the description)
    $queries = Http::recorded(fn ($r) => str_contains($r->url(), 'api/issues?'))->map(fn ($p) => urldecode($p[0]->url()))->values();
    expect($queries->contains(fn ($q) => str_contains($q, 'carregar')))->toBeTrue();
    // the unrelated candidate (nothing in common) is not shown; the one with the same text is a probable duplicate
    $res->assertJsonCount(1, 'issues')->assertJsonPath('issues.0.id', 'DEV-2')->assertJsonPath('issues.0.duplicate', true);
    expect($res->json('issues.0.description_match'))->toBeGreaterThanOrEqual(70)->and($res->json('issues.0.title_match'))->toBeLessThan(80);
});

it('finds a look-alike for a long title by also searching with fewer words', function () {
    $conv = Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    $old = ['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'Protótipo versão 15 do Quiosque de Visitas', 'description' => '', 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    // YouTrack only answers the loose searches: a query with all the words of the long title finds nothing
    Http::fake(['yt.test/api/issues?*' => fn ($r) => Http::response(substr_count(urldecode($r->url()), 'protótipo') && substr_count(urldecode($r->url()), 'figma') ? [] : [$old])]);

    $res = $this->getJson("/api/conversations/{$conv->id}/draft/similar?".http_build_query(['project' => 'DEV', 'summary' => 'Protótipo Figma do Quiosque de Visitas (ecrã 15) a partir do workflow de visitas 17 – IPO Lisboa']))->assertOk();

    $res->assertJsonPath('issues.0.id', 'DEV-1')->assertJsonPath('issues.0.duplicate', false);
    expect($res->json('issues.0.title_match'))->toBeGreaterThanOrEqual(25);
});

it('does not flag a short description as a duplicate by itself', function () {
    $conv = Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    Http::fake(['yt.test/api/issues?*' => Http::response([['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'Erro no pagamento multibanco', 'description' => 'curto', 'project' => ['shortName' => 'DEV'], 'customFields' => []]])]);

    $this->getJson("/api/conversations/{$conv->id}/draft/similar?project=DEV&summary=Erro+no+pagamento&description=curto")->assertOk()
        ->assertJsonPath('issues.0.duplicate', false)->assertJsonPath('issues.0.description_match', null);
});

it('reads multi-value fields (a list of users) and the creator, instead of showing them empty', function () {
    Http::fake(['yt.test/api/issues/DEV-5?*' => Http::response([
        'id' => '2-5', 'idReadable' => 'DEV-5', 'summary' => 'x', 'project' => ['shortName' => 'DEV'],
        'created' => 1760000000000, 'resolved' => 1760100000000, 'reporter' => ['login' => 'rui', 'fullName' => 'Rui Costa'],
        'customFields' => [
            ['name' => 'Assignee', 'value' => [['fullName' => 'Ana Silva', 'login' => 'ana'], ['login' => 'bruno']]], // a list: Assignee is often multi-value
            ['name' => 'Priority', 'value' => ['name' => 'Major']],                                                    // a single value
            ['name' => 'Type', 'value' => []],                                                                         // an empty list
            ['name' => 'State', 'value' => null],
        ],
    ])]);

    $res = $this->getJson('/api/youtrack/issues/DEV-5')->assertOk();

    $res->assertJsonPath('assignee', 'Ana Silva, bruno')->assertJsonPath('priority', 'Major')->assertJsonPath('type', null)->assertJsonPath('state', null)
        ->assertJsonPath('reporter', 'Rui Costa')->assertJsonPath('created', date('c', 1760000000))->assertJsonPath('resolved', date('c', 1760100000));
});

it('keeps working with issues that have no reporter or dates', function () {
    Http::fake(['yt.test/api/issues/DEV-6?*' => Http::response(['id' => '2-6', 'idReadable' => 'DEV-6', 'summary' => 'x', 'project' => ['shortName' => 'DEV'], 'customFields' => []])]);

    $this->getJson('/api/youtrack/issues/DEV-6')->assertOk()->assertJsonPath('reporter', null)->assertJsonPath('created', null)->assertJsonPath('assignee', null);
});

it('lists the issues of the signed-in user (assigned or created, open or resolved) and counts them', function () {
    $row = ['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'meu', 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    Http::fake([
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 4]),
        'yt.test/api/issues?*' => Http::response([$row]),
    ]);

    $this->getJson('/api/youtrack/mine?scope=created&status=all&q=pagamento&project=DEV&state=Open&per_page=12')->assertOk()->assertJsonCount(1, 'issues')->assertJsonPath('total', 4)->assertJsonPath('project_totals.DEV', 4);
    $this->getJson('/api/youtrack/mine')->assertOk();
    $this->getJson('/api/youtrack/mine/summary')->assertOk()->assertExactJson(['assigned_open' => 4, 'assigned_resolved' => 4, 'created' => 4]);
    $this->getJson('/api/youtrack/mine?scope=other')->assertStatus(422);

    $sent = Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), '/api/issues?'))->map(fn ($p) => urldecode($p[0]->url()))->values();
    expect($sent[0])->toContain('reporter: me project: {DEV} State: {Open} pagamento sort by: project asc, updated desc')->toContain('$top=13')->not->toContain('#Unresolved')
        ->and($sent[1])->toContain('for: me #Unresolved sort by: project asc, updated desc')->toContain('$top=13');
});

it('exposes every other custom field of an issue as text, whatever its kind', function () {
    Http::fake(['yt.test/api/issues/DEV-9*' => Http::response([
        'id' => '2-9', 'idReadable' => 'DEV-9', 'summary' => 's', 'project' => ['shortName' => 'DEV', 'name' => 'Desenvolvimento'], 'commentsCount' => 3, 'tags' => [['name' => 'urgente']],
        'customFields' => [
            ['name' => 'Type', '$type' => 'SingleEnumIssueCustomField', 'value' => ['name' => 'Bug']],
            ['name' => 'Environment', '$type' => 'SingleEnumIssueCustomField', 'value' => ['name' => 'Production']],
            ['name' => 'Reviewer', '$type' => 'SingleUserIssueCustomField', 'value' => ['fullName' => 'Ana Silva', 'login' => 'ana']],
            ['name' => 'Estimation', '$type' => 'PeriodIssueCustomField', 'value' => ['presentation' => '2d 4h']],
            ['name' => 'Due Date', '$type' => 'DateIssueCustomField', 'value' => 1782864000000],
            ['name' => 'Story points', '$type' => 'SimpleIssueCustomField', 'value' => 5],
            ['name' => 'Notes', '$type' => 'TextIssueCustomField', 'value' => ['text' => 'ver anexo']],
            ['name' => 'Empty', '$type' => 'SingleEnumIssueCustomField', 'value' => null],
        ],
    ])]);

    $issue = app(YouTrackClient::class)->getIssue('DEV-9');

    expect($issue['project_name'])->toBe('Desenvolvimento')->and($issue['comments'])->toBe(3)->and($issue['labels'])->toBe(['urgente'])
        ->and($issue['custom'])->toBe(['Environment' => 'Production', 'Reviewer' => 'Ana Silva', 'Estimation' => '2d 4h', 'Due Date' => '2026-07-01', 'Story points' => '5', 'Notes' => 'ver anexo']);
});

it('tells each project of a page which of its issues are shown, counting from where the project starts', function () {
    $row = fn (string $key, string $project, string $name) => ['id' => "2-$key", 'idReadable' => $key, 'summary' => $key, 'project' => ['shortName' => $project, 'name' => $name], 'customFields' => []];
    Http::fake([
        'yt.test/api/admin/projects*' => Http::response([
            ['id' => '0-1', 'shortName' => 'AAA', 'name' => 'Alfa'], ['id' => '0-2', 'shortName' => 'BBB', 'name' => 'Beta'], ['id' => '0-3', 'shortName' => 'CCC', 'name' => 'Gama'],
        ]),
        'yt.test/api/issuesGetter/count*' => function ($request) {
            $q = $request['query'] ?? '';

            return Http::response(['count' => match (true) {
                str_contains($q, 'project: {AAA}, {BBB}') => 60,       // the projects that sort before Gama
                str_contains($q, 'project: {CCC}') => 50,
                str_contains($q, 'project: {DDD}') => 3,
                default => 0,
            }]);
        },
        'yt.test/api/issues?*' => Http::response([$row('C-1', 'CCC', 'Gama'), $row('C-2', 'CCC', 'Gama'), $row('D-1', 'DDD', 'Zeta')]),
    ]);

    // page 5 of 12: 48 issues come before it; 60 of those are not Gama's, so... 48 - 60 would be negative: the guess is dropped
    $r = $this->getJson('/api/youtrack/issues?page=5&per_page=12')->assertOk();
    expect($r->json('project_totals'))->toBe(['CCC' => 50, 'DDD' => 3])->and($r->json('project_ranges'))->toBe(['DDD' => [0, 1]]);

    // page 6 (offset 60): Gama's issues start right after the 60 before it, so it begins at 0; Zeta follows from 0
    $r = $this->getJson('/api/youtrack/issues?page=6&per_page=12')->assertOk();
    expect($r->json('project_ranges'))->toBe(['CCC' => [0, 2], 'DDD' => [0, 1]]);

    // the first page needs no guess at all
    $r = $this->getJson('/api/youtrack/issues?page=1&per_page=12')->assertOk();
    expect($r->json('project_ranges'))->toBe(['CCC' => [0, 2], 'DDD' => [0, 1]]);
});

it('serves the analysis numbers computed from the issues of YouTrack', function () {
    $now = now()->getTimestampMs();
    $issue = fn (string $id, int $created, ?int $resolved, ?string $assignee) => [
        'id' => "2-$id", 'created' => $created, 'resolved' => $resolved, 'reporter' => ['fullName' => 'Rui'],
        'project' => ['shortName' => 'DEV', 'name' => 'Dev'],
        'customFields' => array_values(array_filter([
            ['name' => 'State', 'value' => ['name' => $resolved ? 'Done' : 'Open']], ['name' => 'Priority', 'value' => ['name' => 'Normal']],
            $assignee ? ['name' => 'Assignee', 'value' => ['fullName' => $assignee]] : null,
        ])),
    ];
    Http::fake(['yt.test/api/issues?*' => Http::response([
        $issue('1', $now - 86400000, null, 'Ana'), $issue('2', $now - 3 * 86400000, $now - 86400000, 'Ana'), $issue('3', $now - 2 * 86400000, null, null),
    ])]);

    $r = $this->getJson('/api/youtrack/analytics?granularity=month&project=DEV')->assertOk();

    expect($r->json('kpis.total'))->toBe(3)->and($r->json('kpis.open'))->toBe(2)->and($r->json('kpis.unassigned_open'))->toBe(1)
        ->and($r->json('series'))->toHaveCount(12)->and($r->json('assignees.0'))->toMatchArray(['name' => 'Ana', 'open' => 1, 'resolved' => 1])
        ->and($r->json('capped'))->toBeFalse();
    $this->getJson('/api/youtrack/analytics?granularity=day')->assertStatus(422);

    $sent = Http::recorded()->map(fn ($p) => urldecode($p[0]->url()))->first(fn ($u) => str_contains($u, '/api/issues?'));
    expect($sent)->toContain('project: {DEV}');
});

it('orders the lists as asked (flat, no per-project counts) and by project by default', function () {
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    $this->actingAs(yt_user());
    Http::fake(['yt.test/*' => Http::response([])]);

    $this->getJson('/api/youtrack/issues?sort=priority')->assertOk()->assertJsonPath('project_totals', []);
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'sort by: Priority asc, updated desc'));

    $this->getJson('/api/youtrack/issues')->assertOk();
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'sort by: project asc, updated desc'));

    $this->getJson('/api/youtrack/issues?sort=inventado')->assertStatus(422);
});

it('analyses only the issues assigned to the user when asked', function () {
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    $this->actingAs(yt_user());
    $issue = fn (string $who) => ['created' => now()->subDay()->getTimestamp() * 1000, 'project' => ['shortName' => 'DEV', 'name' => 'Dev'],
        'customFields' => [['name' => 'Assignee', 'value' => ['login' => 'x', 'fullName' => $who]]]];
    Http::fake([
        'yt.test/api/users/me*' => Http::response(['login' => 'ana', 'fullName' => 'Ana Silva']),
        'yt.test/api/issues*' => Http::response([$issue('Ana Silva'), $issue('Rui Costa'), $issue('Rui Costa')]),
    ]);

    $this->getJson('/api/youtrack/analytics')->assertOk()->assertJsonPath('kpis.open', 3);
    $this->getJson('/api/youtrack/analytics?mine=1')->assertOk()->assertJsonPath('kpis.open', 1)->assertJsonPath('mine', true);
});

it('creates an issue that has an assignee in the Submitted state', function () {
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    $this->actingAs(yt_user());
    $field = fn (string $name, string $type, array $values = []) => ['field' => ['name' => $name, 'fieldType' => ['isMultiValue' => false]], '$type' => $type, 'bundle' => ['values' => $values]];
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev', 'archived' => false]]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            $field('Type', 'EnumProjectCustomField', [['name' => 'Bug']]),
            $field('Priority', 'EnumProjectCustomField', [['name' => 'Major']]),
            $field('State', 'StateProjectCustomField', [['name' => 'Submitted', 'isResolved' => false], ['name' => 'Open', 'isResolved' => false]]),
            $field('Assignee', 'UserProjectCustomField'),
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([['login' => 'ana', 'fullName' => 'Ana']]),
        'yt.test/api/issues*' => Http::response(['id' => '2-1', 'idReadable' => 'DEV-1']),
        'yt.test/*' => Http::response([]),
    ]);
    $yt = app(\App\Services\YouTrack\YouTrackClient::class);
    $draft = ['summary' => 'x', 'description' => 'd', 'project' => 'DEV', 'type' => 'Bug', 'priority' => 'Major'];
    $states = fn () => collect(Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/issues'))->last()[0]['customFields'])->firstWhere('name', 'State');

    $yt->create($draft + ['assignee' => 'ana']);
    expect($states()['value'])->toBe(['name' => 'Submitted']);

    $yt->create($draft + ['assignee' => 'ana', 'custom_fields' => ['State' => 'Open']]);
    expect($states()['value'])->toBe(['name' => 'Open']);

    $yt->create($draft);
    expect($states())->toBeNull();
});

it('offers and sends the other custom fields of a project: lists, versions, numbers, texts, dates and periods', function () {
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    $this->actingAs(yt_user());
    $def = fn (string $name, string $type, string $ft = 'x', array $values = []) => ['field' => ['name' => $name, 'fieldType' => ['id' => $ft, 'isMultiValue' => false]], '$type' => $type, 'bundle' => ['values' => array_map(fn ($v) => ['name' => $v], $values)]];
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev', 'archived' => false]]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            $def('Type', 'EnumProjectCustomField', 'enum', ['Bug']), $def('Priority', 'EnumProjectCustomField', 'enum', ['Major']),
            $def('Platform', 'OwnedProjectCustomField', 'ownedField', ['Quiosque', 'App']),
            $def('Found in version', 'VersionProjectCustomField', 'version', ['1.0', 'Unknown']),
            $def('Proximo ID', 'SimpleProjectCustomField', 'integer'),
            $def('Migration file', 'SimpleProjectCustomField', 'string'),
            $def('Due Date', 'SimpleProjectCustomField', 'date'),
            $def('Predicted time', 'PeriodProjectCustomField', 'period'),
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([]),
        'yt.test/api/agiles*' => Http::response([]),
        'yt.test/api/issues*' => Http::response(['id' => '2-1', 'idReadable' => 'DEV-1']),
        'yt.test/*' => Http::response([]),
    ]);

    $custom = collect($this->getJson('/api/youtrack/projects/DEV/meta')->assertOk()->json('custom'))->keyBy('key');
    expect($custom->map->kind->all())->toBe(['Platform' => 'enum', 'Found in version' => 'enum', 'Proximo ID' => 'number', 'Migration file' => 'string', 'Due Date' => 'date', 'Predicted time' => 'period'])
        ->and($custom['Platform']['values'])->toBe(['Quiosque', 'App']);

    app(\App\Services\YouTrack\YouTrackClient::class)->create(['summary' => 'x', 'description' => 'd', 'project' => 'DEV', 'type' => 'Bug', 'priority' => 'Major', 'custom_fields' => [
        'Platform' => 'Quiosque', 'Found in version' => '1.0', 'Proximo ID' => '1265', 'Migration file' => 'a.sql', 'Due Date' => '2026-11-01', 'Predicted time' => '2h 30m']]);
    $sent = collect(Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/issues'))->last()[0]['customFields'])->keyBy('name');

    expect($sent['Platform']['$type'])->toBe('SingleOwnedIssueCustomField')
        ->and($sent['Found in version'])->toMatchArray(['$type' => 'SingleVersionIssueCustomField', 'value' => ['name' => '1.0']])
        ->and($sent['Proximo ID'])->toMatchArray(['$type' => 'SimpleIssueCustomField', 'value' => 1265])
        ->and($sent['Migration file']['value'])->toBe('a.sql')
        ->and($sent['Due Date'])->toMatchArray(['$type' => 'DateIssueCustomField', 'value' => strtotime('2026-11-01 12:00:00 UTC') * 1000])
        ->and($sent['Predicted time'])->toMatchArray(['$type' => 'PeriodIssueCustomField', 'value' => ['presentation' => '2h 30m']]);
});

it('reads the fields of a project from its issues when its settings come back empty', function () {
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    $this->actingAs(yt_user());
    $cf = fn (string $name, string $type, string $ft, $value, array $bundle = []) => ['name' => $name, 'value' => $value, 'projectCustomField' => ['$type' => $type, 'field' => ['name' => $name, 'fieldType' => ['id' => $ft]], 'bundle' => ['values' => $bundle]]];
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-75', 'shortName' => 'EDG', 'name' => 'Edigma', 'archived' => false]]),
        'yt.test/api/admin/projects/0-75/customFields*' => Http::response([]),
        'yt.test/api/issues*' => Http::response([
            ['customFields' => [$cf('Type', 'EnumProjectCustomField', 'enum[1]', ['name' => 'Bug'], [['name' => 'Bug'], ['name' => 'Task']]), $cf('Priority', 'EnumProjectCustomField', 'enum[1]', ['name' => 'Major']), $cf('Report Title', 'SimpleProjectCustomField', 'string', 'x')]],
            ['customFields' => [$cf('Priority', 'EnumProjectCustomField', 'enum[1]', ['name' => 'Minor'])]],
        ]),
        'yt.test/*' => Http::response([]),
    ]);

    $meta = $this->getJson('/api/youtrack/projects/EDG/meta')->assertOk()->json();

    expect($meta['types'])->toBe(['Bug', 'Task'])            // the list YouTrack gave for the field
        ->and($meta['priorities'])->toBe(['Major', 'Minor']) // no list given: the values in use
        ->and(collect($meta['custom'])->pluck('kind', 'key')->all())->toBe(['Report Title' => 'string']);
});
