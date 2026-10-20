<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(yt_user());
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    Cache::flush();
});

function directTurn($test, array $similarRows)
{
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev', 'archived' => false]]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type'], '$type' => 'EnumProjectCustomField', 'bundle' => ['values' => [['name' => 'Bug']]]],
            ['field' => ['name' => 'Priority'], '$type' => 'EnumProjectCustomField', 'bundle' => ['values' => [['name' => 'Major']]]],
        ]),
        'yt.test/api/issues?*' => Http::response($similarRows),
        'yt.test/*' => Http::response([]),
    ]);
    $c = $test->postJson('/api/conversations')->json();

    return $test->post("/api/conversations/{$c['id']}/turn", [
        'body' => 'cria um issue para o erro de pagamento multibanco',
        'assistant' => json_encode(['text' => 'Vou criar.', 'direct' => true, 'draft_patch' => [
            'summary' => 'Erro no pagamento multibanco', 'description' => 'Falha ao pagar com multibanco.', 'project' => 'DEV', 'type' => 'Bug', 'priority' => 'Major',
        ]]),
    ])->assertOk();
}

it('creates right away (draft mode off) when nothing looks like a duplicate', function () {
    $assistant = directTurn($this, [])->json('messages.1');

    expect($assistant['meta']['direct'] ?? null)->toBeTrue()->and($assistant['meta'])->not->toHaveKey('card');
});

it('holds the creation and shows the editable card when an issue with the same title already exists', function () {
    $row = ['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'Erro no pagamento multibanco', 'description' => '', 'project' => ['shortName' => 'DEV'], 'customFields' => []];

    $assistant = directTurn($this, [$row])->json('messages.1');

    expect($assistant['meta']['card'] ?? null)->toBeTrue()->and($assistant['meta'])->not->toHaveKey('direct')
        ->and($assistant['body'])->toContain('Vou criar.')->toContain(__('ui.chat.duplicate_held'));
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'api/issues'));
});

it('does not hold the creation for a mere look-alike', function () {
    $row = ['id' => '2-1', 'idReadable' => 'DEV-1', 'summary' => 'Pagamento multibanco com erro intermitente em produção hoje', 'description' => '', 'project' => ['shortName' => 'DEV'], 'customFields' => []];

    $assistant = directTurn($this, [$row])->json('messages.1');

    expect($assistant['meta']['direct'] ?? null)->toBeTrue();
});
