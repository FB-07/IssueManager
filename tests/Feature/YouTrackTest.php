<?php

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
    expect($sent[0])->toContain('project: {DEV} Type: {Show-stopper} for: {ana.silva} pagamento sort by: updated desc')->toContain('$top=26')->toContain('$skip=0')
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
    $conv = \App\Models\Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
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
    $conv = \App\Models\Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
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

it('also flags a differently titled issue whose description is (nearly) the same, and searches the description words too', function () {
    $conv = \App\Models\Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    $text = 'Ao carregar no botão pagar com cartão multibanco o ecrã bloqueia e nunca confirma o pagamento do cliente';
    $row = fn ($n, $title, $desc) => ['id' => "2-$n", 'idReadable' => "DEV-$n", 'summary' => $title, 'description' => $desc, 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    Http::fake(['yt.test/api/issues?*' => Http::sequence()
        ->push([$row(1, 'Checkout bloqueado', 'Texto totalmente diferente sobre faturas, impostos e relatórios mensais do departamento financeiro')])
        ->push([$row(2, 'Pagamento multibanco bloqueado', $text.' em produção')])]);

    $res = $this->getJson("/api/conversations/{$conv->id}/draft/similar?".http_build_query(['project' => 'DEV', 'summary' => 'Pagamento multibanco bloqueia ecrã', 'description' => $text]))->assertOk();

    // the second search (description words) found an issue the title search missed
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'api/issues?'))->count())->toBe(2);
    $res->assertJsonCount(2, 'issues')
        ->assertJsonPath('issues.0.id', 'DEV-2')->assertJsonPath('issues.0.duplicate', true)
        ->assertJsonPath('issues.1.id', 'DEV-1')->assertJsonPath('issues.1.duplicate', false);
    expect($res->json('issues.0.description_match'))->toBeGreaterThanOrEqual(70)->and($res->json('issues.0.title_match'))->toBeLessThan(80);
});

it('does not flag a short description as a duplicate by itself', function () {
    $conv = \App\Models\Conversation::create(['user_id' => auth()->id(), 'title' => 't']);
    $conv->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);
    Http::fake(['yt.test/api/issues?*' => Http::response([['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'Outra coisa', 'description' => 'curto', 'project' => ['shortName' => 'DEV'], 'customFields' => []]])]);

    $this->getJson("/api/conversations/{$conv->id}/draft/similar?project=DEV&summary=Erro+no+pagamento&description=curto")->assertOk()
        ->assertJsonPath('issues.0.duplicate', false)->assertJsonPath('issues.0.description_match', null);
});
