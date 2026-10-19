<?php

use Illuminate\Http\UploadedFile;
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

    Http::assertSent(fn ($r) => $r->header('x-api-key')[0] === 'test-key'
        && $r['stream'] === true
        && collect($r['tools'])->pluck('name')->all() === ['update_issue_draft', 'suggest_replies', 'update_issue', 'find_issues', 'get_issue']
        && str_contains($r['system'], 'NO suggestions'));

    Http::fake(['api.anthropic.com/*' => Http::response(sse([]), 200)]);
    $this->post('/api/chat', chatPayload(['draft_enabled' => false]))->streamedContent();
    Http::assertSent(fn ($r) => collect($r['tools'])->pluck('name')->all() === ['create_issue', 'suggest_replies', 'update_issue', 'find_issues', 'get_issue'] && ! str_contains($r['system'], 'update_issue_draft') && str_contains($r['system'], 'explicitly asks'));
});

it('sends images as base64 blocks and never leaks the key on API errors', function () {
    config(['services.anthropic.key' => 'secret-key']);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key secret-key']], 401)]);

    $out = $this->post('/api/chat', chatPayload() + ['files' => [UploadedFile::fake()->image('a.png', 10, 10)]])->streamedContent();

    expect($out)->toContain('event: error')->not->toContain('secret-key');
    Http::assertSent(fn ($r) => collect($r['messages'][0]['content'])->contains(fn ($b) => $b['type'] === 'image' && $b['source']['media_type'] === 'image/png'));
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
