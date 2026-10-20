<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    test()->actingAs(yt_user());
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
});

function sse(array $events): string
{
    return collect($events)->map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n")->implode('');
}

function chatPayload(array $over = []): array
{
    return ['payload' => json_encode($over + [
        'messages' => [['role' => 'user', 'content' => 'O botão tem 15px de altura']],
        'draft_enabled' => true,
        'draft' => null,
        'catalog' => ['types' => ['Bug', 'Task', 'Feature']],
    ])];
}

it('refuses when the API key is missing', function () {
    config(['services.anthropic.key' => null]);
    $this->post('/api/chat', chatPayload())->assertStatus(503);
});

it('streams text and the draft tool call as SSE and sends the tool only when enabled', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Olá ']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'mundo']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'name' => 'update_issue_draft']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"summary":"Altura ']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '15px"}']],
        ['type' => 'content_block_stop', 'index' => 1],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    $out = $this->post('/api/chat', chatPayload())->streamedContent();

    expect($out)->toContain('event: text')->toContain('Olá ')->toContain('event: draft')
        ->toContain('"summary":"Altura 15px"')->toContain('event: done');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && $r->header('x-api-key')[0] === 'test-key'
        && $r['stream'] === true
        && collect($r['tools'])->pluck('name')->all() === ['update_issue_draft', 'suggest_replies', 'update_issue', 'propose_issues', 'find_issues', 'count_issues', 'get_issue']
        && str_contains($r['system'], 'NO suggestions'));

    Http::fake(['api.anthropic.com/*' => Http::response(sse([]), 200)]);
    $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && collect($r['tools'])->pluck('name')->all() === ['create_issue', 'suggest_replies', 'update_issue', 'propose_issues', 'find_issues', 'count_issues', 'get_issue'] && ! str_contains($r['system'], 'update_issue_draft') && str_contains($r['system'], 'explicitly asks'));
});

it('sends images as base64 blocks and never leaks the key on API errors', function () {
    config(['services.anthropic.key' => 'secret-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key secret-key']], 401)]);

    $out = $this->post('/api/chat', chatPayload() + ['files' => [UploadedFile::fake()->image('a.png', 10, 10)]])->streamedContent();

    expect($out)->toContain('event: error')->not->toContain('secret-key');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && collect($r['messages'][0]['content'])->contains(fn ($b) => $b['type'] === 'image' && $b['source']['media_type'] === 'image/png'));
});

it('forwards the create_issue tool call as a create event when the draft mode is off', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'name' => 'create_issue']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"message":"A criar","summary":"Erro 500"}']],
        ['type' => 'content_block_stop', 'index' => 0],
    ]), 200)]);

    $out = $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    expect($out)->toContain('event: create')->toContain('"summary":"Erro 500"')->not->toContain('event: draft');
});

it('runs find_issues on the server, feeds the result back and then forwards the update_issue call', function () {
    config(['services.anthropic.key' => 'test-key']);
    $round1 = sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'find_issues']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"botão pagar"}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]);
    $round2 = sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'update_issue']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"issue_id":"DEV-12","message":"Vou alterar","summary":"Novo título"}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]);
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push($round1, 200)->push($round2, 200),
        'yt.test/api/issues?*' => Http::response([['id' => '2-12', 'idReadable' => 'DEV-12', 'summary' => 'Botão de pagar falha', 'project' => ['shortName' => 'DEV'], 'customFields' => []]]),
    ]);

    $out = $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    expect($out)->toContain('event: update')->toContain('"issue_id":"DEV-12"')->toContain('event: done')->not->toContain('event: error');

    $anthropic = Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->values();
    expect($anthropic)->toHaveCount(2);
    $second = $anthropic[1][0]->data();
    expect($second['messages'])->toHaveCount(3)
        ->and($second['messages'][1]['content'][0])->toMatchArray(['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'find_issues'])
        ->and($second['messages'][2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'tu_1'])
        ->and($second['messages'][2]['content'][0]['content'])->toContain('DEV-12')->toContain('Botão de pagar falha');
});

it('forwards suggest_replies as a sanitized replies event (max 4, no duplicates, valid kinds)', function () {
    config(['services.anthropic.key' => 'test-key']);
    $input = json_encode(['replies' => [
        ['label' => 'Produção', 'kind' => 'text'],
        ['label' => 'Fábio Barros', 'text' => 'Atribui ao Fábio Barros', 'kind' => 'person', 'value' => 'fabio.barros'],
        ['label' => 'produção'], // duplicate (case-insensitive)
        ['label' => 'Proximo', 'kind' => 'project', 'value' => '2010043'],
        ['label' => 'Staging', 'kind' => 'evil'], // unknown kind -> text
        ['label' => 'Quinto botão'], // beyond the cap
        ['label' => '   '], ['nothing' => true],
    ]]);
    Http::fake(['api.anthropic.com/*' => Http::response(sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'name' => 'suggest_replies']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $input]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]), 200)]);

    $out = $this->post('/api/chat', chatPayload())->streamedContent();

    preg_match('/event: replies\ndata: (.+)\n/', $out, $m);
    $replies = json_decode($m[1], true)['replies'];

    expect($out)->toContain('event: done')->not->toContain('event: error')
        ->and(array_column($replies, 'label'))->toBe(['Produção', 'Fábio Barros', 'Proximo', 'Staging'])
        ->and($replies[1])->toBe(['label' => 'Fábio Barros', 'text' => 'Atribui ao Fábio Barros', 'kind' => 'person', 'value' => 'fabio.barros'])
        ->and($replies[3]['kind'])->toBe('text');
});

it('does not emit a replies event when the model offers nothing usable', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'name' => 'suggest_replies']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"replies":"nope"}']],
        ['type' => 'content_block_stop', 'index' => 0],
    ]), 200)]);

    expect($this->post('/api/chat', chatPayload())->streamedContent())->not->toContain('event: replies')->toContain('event: done');
});

it('forwards propose_issues as a batch event with the proposals', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'name' => 'propose_issues']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"message":"Encontrei 2","issues":[{"summary":"A"},{"summary":"B"}]}']],
        ['type' => 'content_block_stop', 'index' => 0],
    ]), 200)]);

    $out = $this->post('/api/chat', chatPayload())->streamedContent();

    expect($out)->toContain('event: batch')->toContain('"message":"Encontrei 2"')->toContain('"summary":"B"')->not->toContain('event: error');
});

function chatSystem(array $over = []): string
{
    Http::fake([
        'yt.test/api/users/me*' => Http::response(['id' => '1-1', 'login' => 'ana.silva', 'fullName' => 'Ana Silva']),
        'api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200),
    ]);
    test()->post('/api/chat', chatPayload($over))->streamedContent();

    return Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->first()[0]['system'];
}

it('tells Claude from the first message who is signed in, so "assign it to me" can be resolved', function () {
    config(['services.anthropic.key' => 'test-key']);
    test()->actingAs(yt_user('t', ['name' => 'Nome da App', 'email' => 'ana.silva@proside.pt']));

    foreach ([true, false] as $draftMode) {
        $system = chatSystem(['draft_enabled' => $draftMode]);

        // the name comes from YouTrack itself, together with the login that assignees are identified by
        expect($system)->toContain('Ana Silva <ana.silva@proside.pt>')->toContain('`ana.silva`')->toContain('assign it to me')->toContain('atribui-me')
            ->toContain("project's `assignees` list")->toContain('Never guess their gender')->not->toContain('Nome da App');
    }
});

it('still names the user when YouTrack cannot be asked, and says the login is unknown', function () {
    config(['services.anthropic.key' => 'test-key']);
    test()->actingAs(yt_user('t', ['name' => 'Rui Costa', 'email' => 'rui@proside.pt']));
    Http::fake([
        'yt.test/api/users/me*' => Http::response(['error' => 'boom'], 500),
        'api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200),
    ]);

    test()->post('/api/chat', chatPayload())->streamedContent();
    $system = Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->first()[0]['system'];

    expect($system)->toContain('Rui Costa <rui@proside.pt>')->toContain('login is unknown')->not->toContain('set `assignee` to that login');
});

it('uses the YouTrack identity for the local dev user and never shows its fake email', function () {
    config(['services.anthropic.key' => 'test-key']);
    test()->actingAs(yt_user('t', ['name' => 'Dev', 'email' => \App\Http\Controllers\AuthController::DEV_EMAIL]));

    $system = chatSystem();

    expect($system)->toContain('Ana Silva')->toContain('`ana.silva`')->not->toContain('dev@issuemanager.test')->not->toContain('Dev <');
});

it('asks YouTrack who the user is only once in a while', function () {
    config(['services.anthropic.key' => 'test-key']);
    test()->actingAs(yt_user());
    Cache::flush();
    Http::fake([
        'yt.test/api/users/me*' => Http::response(['id' => '1-1', 'login' => 'ana.silva', 'fullName' => 'Ana Silva']),
        'api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200),
    ]);

    test()->post('/api/chat', chatPayload())->streamedContent();
    test()->post('/api/chat', chatPayload())->streamedContent();

    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'users/me'))->count())->toBe(1)
        ->and(Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->count())->toBe(2);
});


function lookupRound(string $tool, array $input): string
{
    return sse([
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => $tool]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input)]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]);
}

it('counts issues on the server with count_issues, so "how many" is the real total and not the size of a page', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push(lookupRound('count_issues', ['query' => 'for: me #Unresolved']), 200)->push(sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Tens 137.']],
            ['type' => 'content_block_stop', 'index' => 0],
        ]), 200),
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 137]),
    ]);

    $out = $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    expect($out)->toContain('Tens 137.')->toContain('event: done')->not->toContain('event: error');
    $second = Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->values()[1][0]->data();
    expect($second['messages'][2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'tu_1'])
        ->and(json_decode($second['messages'][2]['content'][0]['content'], true))->toMatchArray(['total' => 137]);
    // the query goes to YouTrack as typed (every project: no `project:` filter is added)
    Http::assertSent(fn ($r) => str_contains($r->url(), 'issuesGetter/count') && str_contains($r['query'], 'for: me #Unresolved') && ! str_contains($r['query'], 'project:'));
});

it('lists issues with find_issues by pages of up to 50 and reports the total and whether there is more', function () {
    config(['services.anthropic.key' => 'test-key']);
    $row = fn ($n) => ['id' => "2-$n", 'idReadable' => "DEV-$n", 'summary' => "Issue $n", 'project' => ['shortName' => 'DEV'], 'customFields' => []];
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push(lookupRound('find_issues', ['query' => 'for: me #Unresolved', 'limit' => 50, 'page' => 2]), 200)->push(sse([]), 200),
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 137]),
        'yt.test/api/issues?*' => Http::response(array_map($row, range(1, 51))), // one extra row tells there is a next page
    ]);

    $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    $second = Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->values()[1][0]->data();
    $result = json_decode($second['messages'][2]['content'][0]['content'], true);
    expect($result)->toMatchArray(['total' => 137, 'page' => 2, 'shown' => 50, 'has_more' => true])->and($result['issues'])->toHaveCount(50);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api/issues?') && str_contains(urldecode($r->url()), '$top=51') && str_contains(urldecode($r->url()), '$skip=50'));
});

it('clamps absurd page sizes and tells Claude how to count and list in the prompt', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push(lookupRound('find_issues', ['query' => 'x', 'limit' => 5000]), 200)->push(sse([]), 200),
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 0]),
        'yt.test/api/issues?*' => Http::response([]),
    ]);

    $this->post('/api/chat', chatPayload())->streamedContent();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api/issues?') && str_contains(urldecode($r->url()), '$top=201')); // 5000 asked, 200 allowed (+1 to know if there is more)
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'], 'call `count_issues`') && str_contains($r['system'], 'for: me') && str_contains($r['system'], 'has_more'));
});

it('lets Claude choose and build lists, tables and groupings by itself, in both modes', function () {
    config(['services.anthropic.key' => 'test-key']);
    foreach ([true, false] as $draftMode) {
        Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
        $this->post('/api/chat', chatPayload(['draft_enabled' => $draftMode]))->streamedContent();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic')
            // it decides the form, and does what is named without asking
            && str_contains($r['system'], 'YOU decide the best form') && str_contains($r['system'], '"tabela"/"em tabela" -> a Markdown table')
            && str_contains($r['system'], 'means GROUP BY that field') && str_contains($r['system'], 'You compute groupings, counts and sorting yourself')
            && str_contains($r['system'], 'no "here is the list/table"')
            // lists of issues: one per line unless another form is asked; counts per group come from count_issues
            && str_contains($r['system'], 'Unless the user asks for another form') && str_contains($r['system'], 'ONE issue per line') && str_contains($r['system'], 'Never put several issues in one paragraph')
            && str_contains($r['system'], 'call `count_issues` once per group'));
    }
});

function textBlock(string $text, int $index = 0): array
{
    return [
        ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, 0, 6)]],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, 6)]],
        ['type' => 'content_block_stop', 'index' => $index],
    ];
}

it('drops the narration before a lookup and sends the complete answer in one piece', function () {
    config(['services.anthropic.key' => 'test-key']);
    $round1 = sse([
        ...textBlock('Aqui está a lista:'),
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'find_issues']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"for: me #Unresolved","limit":50}']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]);
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push($round1, 200)->push(sse([...textBlock("Tens 3 issues:\n- **DEV-1** — a\n- **DEV-2** — b\n- **DEV-3** — c"), ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]), 200),
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 3]),
        'yt.test/api/issues?*' => Http::response([]),
    ]);

    $out = $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    // the intro never reaches the user; the answer arrives once, whole (not word by word after a pause)
    expect($out)->not->toContain('Aqui está')->and($out)->toContain('Tens 3 issues:')->toContain('DEV-3')
        ->and(substr_count($out, 'event: text'))->toBe(1)->and($out)->toContain('event: done');
    // the model itself still remembers what it said before the lookup
    $second = Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->values()[1][0]->data();
    expect($second['messages'][1]['content'][0])->toMatchArray(['type' => 'text', 'text' => 'Aqui está a lista:']);
});

it('keeps the text written before a tool the app applies itself, and a plain answer still arrives', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(sse([
        ...textBlock('Vou alterar já.'),
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'update_issue']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"issue_id":"DEV-1","message":"ok","summary":"Novo"}']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
    ]), 200)]);

    $out = $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    expect($out)->toContain('Vou alterar já.')->toContain('event: update')->toContain('"issue_id":"DEV-1"');
});

it('tells Claude to call the lookup tools without writing anything first', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $this->post('/api/chat', chatPayload())->streamedContent();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'], 'Do not write anything before calling these lookup tools'));
});


it('lists many issues cheaply: rows instead of objects, no descriptions fetched, up to 200 per page', function () {
    config(['services.anthropic.key' => 'test-key']);
    $row = fn ($n) => ['id' => "2-$n", 'idReadable' => "DEV-$n", 'summary' => str_repeat('x', 300), 'project' => ['shortName' => 'DEV'],
        'created' => 1760000000000, 'reporter' => ['login' => 'rui', 'fullName' => 'Rui Costa'],
        // Assignee is a multi-value field: YouTrack sends a list of users
        'customFields' => [['name' => 'State', 'value' => ['name' => 'Open']], ['name' => 'Assignee', 'value' => [['fullName' => 'Mariana Tavares', 'login' => 'mariana'], ['fullName' => 'Rui Costa', 'login' => 'rui']]]]];
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push(lookupRound('find_issues', ['query' => 'for: mariana.tavares #Unresolved', 'limit' => 200]), 200)->push(sse([]), 200),
        'yt.test/api/issuesGetter/count*' => Http::response(['count' => 568]),
        'yt.test/api/issues?*' => Http::response(array_map($row, range(1, 201))),
    ]);

    $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();

    $result = json_decode(Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->values()[1][0]->data()['messages'][2]['content'][0]['content'], true);
    expect($result)->toMatchArray(['total' => 568, 'shown' => 200, 'has_more' => true])
        ->and($result['columns'])->toBe(['id', 'summary', 'project', 'type', 'priority', 'assignee', 'reporter', 'state', 'created', 'resolved'])
        // assigned to two people (a list), created by Rui on a given day, still open
        ->and($result['issues'][0][0])->toBe('DEV-1')->and($result['issues'][0][5])->toBe('Mariana Tavares, Rui Costa')->and($result['issues'][0][6])->toBe('Rui Costa')
        ->and($result['issues'][0][7])->toBe('Open')->and($result['issues'][0][8])->toBe('2025-10-09')->and($result['issues'][0][9])->toBeNull()
        ->and(mb_strlen($result['issues'][0][1]))->toBe(140);
    // the list search does not ask YouTrack for the (long) descriptions
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api/issues?') && ! str_contains(urldecode($r->url()), 'description'));
});

it('accepts a very long earlier answer in the history and sends the model only a shortened copy', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $list = "Tens 568 issues:\n".implode("\n", array_map(fn ($n) => "- **DEV-$n** — Issue número $n · _Open_", range(1, 3000))); // ~100k characters

    $this->post('/api/chat', chatPayload(['messages' => [
        ['role' => 'user', 'content' => 'lista os meus issues'],
        ['role' => 'assistant', 'content' => $list],
        ['role' => 'user', 'content' => 'e quais estão em Testing?'],
    ]]))->assertOk()->streamedContent();

    $sent = Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->first()[0]->data()['messages'];
    expect(mb_strlen($list))->toBeGreaterThan(90000)
        ->and($sent[1]['content'])->toStartWith('Tens 568 issues:')->toContain('characters of this earlier answer were left out')->toContain('DEV-3000')
        ->and(mb_strlen($sent[1]['content']))->toBeLessThan(6500)
        ->and($sent[0]['content'])->toBe('lista os meus issues')->and($sent[2]['content'][0]['text'])->toBe('e quais estão em Testing?'); // the new message goes as a content block
});

it('does not touch a normal earlier answer, and still limits what the user types', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $answer = str_repeat('Resposta normal. ', 200); // ~3.4k characters

    $this->post('/api/chat', chatPayload(['messages' => [['role' => 'user', 'content' => 'olá'], ['role' => 'assistant', 'content' => $answer], ['role' => 'user', 'content' => 'obrigado']]]))->assertOk()->streamedContent();
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->first()[0]->data()['messages'][1]['content'])->toBe($answer);

    $this->post('/api/chat', chatPayload(['messages' => [['role' => 'user', 'content' => str_repeat('a', 30001)]]]))->assertStatus(422)->assertJsonPath('message', __('ui.errors.message_too_long', ['max' => 30000]));
    $this->post('/api/chat', chatPayload(['messages' => [['role' => 'user', 'content' => 'x'], ['role' => 'assistant', 'content' => str_repeat('a', 400001)], ['role' => 'user', 'content' => 'y']]]))->assertStatus(422);
});

it('stores a long answer of the assistant (hundreds of issues) in the conversation', function () {
    $id = $this->postJson('/api/conversations')->json('id');
    $text = implode("\n", array_map(fn ($n) => "- **DEV-$n** — Issue número $n", range(1, 6000))); // ~190k characters

    $this->post("/api/conversations/$id/turn", ['body' => 'lista tudo', 'assistant' => json_encode(['text' => $text])])->assertOk();

    expect(mb_strlen($text))->toBeGreaterThan(150000)->and(\App\Models\Message::where('conversation_id', $id)->where('role', 'assistant')->first()->body)->toBe($text);
});


it('tells Claude how to tell "assigned to" from "created by" and not to call a field empty without looking', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $this->post('/api/chat', chatPayload())->streamedContent();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'], '`reporter: {login}` (CREATED by someone)') && str_contains($r['system'], '(ASSIGNED to someone)')
        && str_contains($r['system'], 'never claim a field is empty unless the rows show it empty') && str_contains($r['system'], 'try the other syntax'));
});
