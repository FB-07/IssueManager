<?php

use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = yt_user();
    $this->actingAs($this->user);
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok']);
    Cache::flush();
    $this->fakeYouTrack = fn (array $extra = []) => Http::fake($extra + [
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev', 'archived' => false]]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], '$type' => 'EnumProjectCustomField', 'bundle' => ['values' => [['name' => 'Bug'], ['name' => 'Task']]]],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], '$type' => 'EnumProjectCustomField', 'bundle' => ['values' => [['name' => 'Major'], ['name' => 'Minor']]]],
        ]),
        'yt.test/api/issues*' => Http::sequence()->push(['id' => '2-1', 'idReadable' => 'DEV-1'])->push(['id' => '2-2', 'idReadable' => 'DEV-2']),
        'yt.test/*' => Http::response([]),
    ]);
});

function batchProposal(array $over = []): array
{
    return $over + ['summary' => 'Corrigir login', 'description' => 'desc', 'project' => 'DEV', 'type' => 'Bug', 'priority' => 'Major'];
}

function batchTurn($test, array $batch)
{
    $c = test()->postJson('/api/conversations')->json();
    $res = test()->post("/api/conversations/{$c['id']}/turn", [
        'body' => 'ata da reunião',
        'assistant' => json_encode(['text' => 'Encontrei itens', 'batch' => $batch]),
    ])->assertOk();

    return [$c['id'], $res->json('messages.1')];
}

it('stores sanitized proposals on the assistant message without creating anything', function () {
    ($this->fakeYouTrack)();
    [, $msg] = batchTurn($this, [batchProposal(), batchProposal(['summary' => 'Outro', 'type' => 'Inventado']), ['description' => 'sem título']]);

    expect($msg['meta']['batch']['items'])->toHaveCount(2)
        ->and($msg['meta']['batch']['items'][0])->toMatchArray(['summary' => 'Corrigir login', 'status' => 'pending', 'type' => 'Bug'])
        ->and($msg['meta']['batch']['items'][1])->not->toHaveKey('type');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'yt.test/api/issues'));
});

it('creates only the approved items, applying the edits, and marks the discarded ones', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal(), batchProposal(['summary' => 'Segundo']), batchProposal(['summary' => 'Terceiro'])]);

    $res = $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", [
        'create' => [['index' => 0, 'summary' => 'Título editado', 'priority' => 'Minor']],
        'discard' => [2],
    ])->assertOk();

    $items = $res->json('message.meta.batch.items');
    expect($items[0])->toMatchArray(['status' => 'created', 'issue_key' => 'DEV-1', 'summary' => 'Título editado', 'priority' => 'Minor'])
        ->and($items[1]['status'])->toBe('pending')
        ->and($items[2]['status'])->toBe('discarded');

    // an already created item is never created twice
    $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['create' => [['index' => 0]]])->assertOk();
    expect(Http::recorded(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'api/issues'))->count())->toBe(1);
});

it('keeps a failing item pending with its error and still creates the others', function () {
    ($this->fakeYouTrack)(['yt.test/api/issues*' => Http::sequence()->push(['error' => 'boom'], 500)->push(['id' => '2-2', 'idReadable' => 'DEV-2'])]);
    [$cid, $msg] = batchTurn($this, [batchProposal(), batchProposal(['summary' => 'Segundo'])]);

    $items = $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['create' => [['index' => 0], ['index' => 1]]])
        ->assertOk()->json('message.meta.batch.items');

    expect($items[0]['status'])->toBe('pending')->and($items[0]['error'])->not->toBeEmpty()
        ->and($items[1])->toMatchArray(['status' => 'created', 'issue_key' => 'DEV-2']);
});

it('refuses to create an item that is missing required fields', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [['summary' => 'Sem campos']]);

    $item = $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['create' => [['index' => 0]]])
        ->assertOk()->json('message.meta.batch.items.0');

    expect($item['status'])->toBe('pending')->and($item['missing'])->toContain('project');
});

it("does not let another user touch someone else's batch", function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal()]);

    $this->actingAs(yt_user('other', ['email' => 'other@example.com']));
    $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['create' => [['index' => 0]]])->assertNotFound();
    expect(Message::find($msg['id'])->meta['batch']['items'][0]['status'])->toBe('pending');
});

it('lists look-alikes of one proposal from its live text and flags probable duplicates', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal(['summary' => 'Erro no pagamento multibanco']), batchProposal(['summary' => 'Outro'])]);
    Http::swap(new \Illuminate\Http\Client\Factory); // the first stubs registered win, so start from a clean fake
    Http::fake(['yt.test/api/issues?*' => Http::response([
        ['id' => '2-9', 'idReadable' => 'DEV-9', 'summary' => 'Erro no pagamento multibanco', 'description' => '', 'project' => ['shortName' => 'DEV'], 'customFields' => []],
        ['id' => '2-8', 'idReadable' => 'DEV-8', 'summary' => 'Pagamento multibanco com erro intermitente em produção', 'description' => '', 'project' => ['shortName' => 'DEV'], 'customFields' => []],
    ])]);

    $res = $this->getJson("/api/conversations/$cid/messages/{$msg['id']}/batch/0/similar?".http_build_query(['project' => 'DEV', 'summary' => 'Erro no pagamento multibanco']))->assertOk();

    $res->assertJsonPath('issues.0.id', 'DEV-9')->assertJsonPath('issues.0.duplicate', true)->assertJsonPath('issues.1.duplicate', false);

    $this->getJson("/api/conversations/$cid/messages/{$msg['id']}/batch/7/similar?summary=x&project=DEV")->assertNotFound();
});

it('does not look for look-alikes of a proposal that is already decided, nor for another user', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal()]);
    $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['discard' => [0]])->assertOk();

    $this->getJson("/api/conversations/$cid/messages/{$msg['id']}/batch/0/similar?summary=Corrigir+login&project=DEV")->assertOk()->assertJsonCount(0, 'issues');

    $this->actingAs(yt_user('other', ['email' => 'other@example.com']));
    $this->getJson("/api/conversations/$cid/messages/{$msg['id']}/batch/0/similar?summary=x&project=DEV")->assertNotFound();
});
