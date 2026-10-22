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
        'yt.test/api/issues?*' => function ($r) {
            static $created = 0;
            if ($r->method() === 'GET') {
                return Http::response([]); // searches (look-alikes, who resolved similar issues): nothing found
            }

            return Http::response(['id' => '2-'.(++$created), 'idReadable' => 'DEV-'.$created]);
        },
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
    ($this->fakeYouTrack)(['yt.test/api/issues?*' => function ($r) {
        static $posts = 0;
        if ($r->method() === 'GET') {
            return Http::response([]);
        }

        return ++$posts === 1 ? Http::response(['error' => 'boom'], 500) : Http::response(['id' => '2-2', 'idReadable' => 'DEV-2']);
    }]);
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

it('keeps the review in progress: edits and decisions survive a reload and nothing is created', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal(), batchProposal(['summary' => 'Segundo']), batchProposal(['summary' => 'Terceiro'])]);

    $this->patchJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['items' => [
        ['index' => 0, 'summary' => 'Título editado', 'description' => 'nova', 'priority' => 'Minor', 'decision' => 'accepted'],
        ['index' => 1, 'decision' => 'rejected'],
    ]])->assertOk()->assertJsonPath('saved', true);

    $items = collect($this->getJson("/api/conversations/$cid")->json('messages'))->firstWhere('id', $msg['id'])['meta']['batch']['items'];
    expect($items[0])->toMatchArray(['summary' => 'Título editado', 'description' => 'nova', 'priority' => 'Minor', 'decision' => 'accepted', 'status' => 'pending'])
        ->and($items[1])->toMatchArray(['summary' => 'Segundo', 'decision' => 'rejected', 'status' => 'pending'])
        ->and($items[2])->not->toHaveKey('decision');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'yt.test/api/issues'));
});

it('lets the user undo a decision and empty a list when saving', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal(['custom_fields' => []])]);
    $url = "/api/conversations/$cid/messages/{$msg['id']}/batch";

    $this->patchJson($url, ['items' => [['index' => 0, 'decision' => 'accepted', 'labels' => ['a', 'b']]]])->assertOk();
    $this->patchJson($url, ['items' => [['index' => 0, 'decision' => null, 'labels' => [], 'assignee' => '']]])->assertOk();

    $item = collect($this->getJson("/api/conversations/$cid")->json('messages'))->firstWhere('id', $msg['id'])['meta']['batch']['items'][0];
    expect($item['decision'])->toBeNull()->and($item['labels'])->toBe([]);
});

it('does not touch created or discarded proposals when saving', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal(), batchProposal(['summary' => 'Segundo'])]);
    $this->postJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['create' => [['index' => 0]], 'discard' => [1]])->assertOk();

    $this->patchJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['items' => [
        ['index' => 0, 'summary' => 'Alterado depois', 'decision' => 'rejected'], ['index' => 1, 'summary' => 'Alterado também'],
    ]])->assertOk();

    $items = collect($this->getJson("/api/conversations/$cid")->json('messages'))->firstWhere('id', $msg['id'])['meta']['batch']['items'];
    expect($items[0])->toMatchArray(['status' => 'created', 'summary' => 'Corrigir login'])->and($items[1])->toMatchArray(['status' => 'discarded', 'summary' => 'Segundo']);
});

it("validates the saved review and keeps it private to its owner", function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal()]);
    $url = "/api/conversations/$cid/messages/{$msg['id']}/batch";

    $this->patchJson($url, ['items' => [['index' => 0, 'decision' => 'maybe']]])->assertStatus(422);
    $this->patchJson($url, [])->assertStatus(422);

    $this->actingAs(yt_user('other', ['email' => 'other2@example.com']));
    $this->patchJson($url, ['items' => [['index' => 0, 'summary' => 'roubado']]])->assertNotFound();
});

it('attaches the files ticked for each proposal to its new issue, and only those', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    ($this->fakeYouTrack)([
        'yt.test/api/issues/DEV-1/attachments*' => Http::response([['id' => '1-1']]),
        'yt.test/api/issues/DEV-2/attachments*' => Http::response([['id' => '1-2']]),
    ]);
    $c = $this->postJson('/api/conversations')->json();
    $res = $this->post("/api/conversations/{$c['id']}/turn", [
        'body' => 'ata + log',
        'files' => [\Illuminate\Http\UploadedFile::fake()->create('erro.log', 2, 'text/plain'), \Illuminate\Http\UploadedFile::fake()->create('ecra.png', 2, 'image/png')],
        'assistant' => json_encode(['text' => 'Itens', 'batch' => [batchProposal(), batchProposal(['summary' => 'Segundo'])]]),
    ])->assertOk();
    [$log, $img] = collect($res->json('messages.0.attachments'))->pluck('id')->all();

    // by default every proposal gets the files that came with the text
    expect($res->json('messages.1.meta.batch.items.0.attachments'))->toBe([$log, $img])->and($res->json('messages.1.meta.batch.items.1.attachments'))->toBe([$log, $img]);
    $mid = $res->json('messages.1.id');

    $out = $this->postJson("/api/conversations/{$c['id']}/messages/$mid/batch", ['create' => [
        ['index' => 0, 'attachments' => [$log]],            // only the log
        ['index' => 1, 'attachments' => [$img, 99999]],     // the image; an unknown id is dropped
    ]])->assertOk()->json('message.meta.batch.items');

    expect($out[0]['attachments_sent'])->toBe(['erro.log'])->and($out[1]['attachments_sent'])->toBe(['ecra.png'])->and($out[1]['attachments'])->toBe([$img]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'DEV-1/attachments') && str_contains($r->body(), 'erro.log') && ! str_contains($r->body(), 'ecra.png'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'DEV-2/attachments') && str_contains($r->body(), 'ecra.png') && ! str_contains($r->body(), 'erro.log'));
    // a file can go to several issues: it is not marked as "sent to" one of them
    expect(\Illuminate\Support\Facades\DB::table('attachments')->whereNotNull('youtrack_issue')->count())->toBe(0);
});

it('keeps the ticked files with the saved review and ignores files of other conversations', function () {
    ($this->fakeYouTrack)();
    [$cid, $msg] = batchTurn($this, [batchProposal()]);
    $other = $this->postJson('/api/conversations')->json();
    $foreign = \App\Models\Attachment::create(['conversation_id' => $other['id'], 'kind' => 'file', 'name' => 'x.txt', 'mime' => 'text/plain', 'size' => 1, 'path' => 'attachments/x.txt'])->id;

    $this->patchJson("/api/conversations/$cid/messages/{$msg['id']}/batch", ['items' => [['index' => 0, 'attachments' => [$foreign], 'decision' => 'accepted']]])->assertOk();

    $item = collect($this->getJson("/api/conversations/$cid")->json('messages'))->firstWhere('id', $msg['id'])['meta']['batch']['items'][0];
    expect($item['attachments'])->toBe([]);
});

it('ticks every file of the conversation for a batch proposed later, not only the files of the triggering message', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    ($this->fakeYouTrack)();
    $c = $this->postJson('/api/conversations')->json();
    $first = $this->post("/api/conversations/{$c['id']}/turn", [
        'body' => 'olha esta imagem', 'files' => [\Illuminate\Http\UploadedFile::fake()->create('logo.png', 2, 'image/png')],
        'assistant' => json_encode(['text' => 'Recebi']),
    ])->assertOk();
    $logo = $first->json('messages.0.attachments.0.id');

    // later, a text without files produces the batch: the earlier image is still ticked
    $res = $this->post("/api/conversations/{$c['id']}/turn", [
        'body' => 'ata da reunião e anexa a imagem',
        'assistant' => json_encode(['text' => 'Itens', 'batch' => [batchProposal()]]),
    ])->assertOk();

    expect($res->json('messages.1.meta.batch.items.0.attachments'))->toBe([$logo]);
});
