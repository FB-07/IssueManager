<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(yt_user());
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok', 'services.anthropic.key' => 'test-key', 'services.youtrack.title_prefix' => '']);
    Cache::flush();
});

function ctxFake(array $extra = []): void
{
    Http::fake($extra + [
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev', 'archived' => false]]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], '$type' => 'EnumProjectCustomField', 'bundle' => ['values' => [['name' => 'Bug']]]],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], '$type' => 'EnumProjectCustomField', 'bundle' => ['values' => [['name' => 'Major']]]],
            ['field' => ['name' => 'State', 'fieldType' => ['isMultiValue' => false]], '$type' => 'StateProjectCustomField', 'bundle' => ['values' => [['name' => 'Open'], ['name' => 'Done']]]],
        ]),
        'yt.test/api/issues/DEV-7?*' => Http::response(['id' => '2-7', 'idReadable' => 'DEV-7', 'summary' => 'Botão falha', 'description' => 'O botão não responde', 'project' => ['shortName' => 'DEV'],
            'customFields' => [['name' => 'Priority', 'value' => ['name' => 'Major']], ['name' => 'State', 'value' => ['name' => 'Open']]]]),
        'yt.test/api/issues/DEV-404?*' => Http::response(['error' => 'not found'], 404),
        'yt.test/api/issues?*' => Http::response(['id' => '2-9', 'idReadable' => 'DEV-9']),
        'yt.test/*' => Http::response([]),
    ]);
}

function ctxConv($test): int
{
    return $test->postJson('/api/conversations')->json('id');
}

it('links a chat to an existing issue and clears the link', function () {
    ctxFake();
    $id = ctxConv($this);

    $this->putJson("/api/conversations/$id/context", ['issue_key' => 'DEV-7'])->assertOk()
        ->assertJsonPath('conversation.context.key', 'DEV-7')->assertJsonPath('conversation.context.summary', 'Botão falha')
        ->assertJsonPath('conversation.context.url', 'https://yt.test/issue/DEV-7')->assertJsonPath('issue.state', 'Open');
    expect($this->getJson("/api/conversations/$id")->json('conversation.context.key'))->toBe('DEV-7');

    $this->putJson("/api/conversations/$id/context", ['issue_key' => null])->assertOk()->assertJsonPath('conversation.context', null)->assertJsonPath('issue', null);
    expect(Conversation::find($id)->issue_key)->toBeNull();
});

it('refuses a context that is not a valid or visible issue and keeps it private to the owner', function () {
    ctxFake();
    $id = ctxConv($this);

    $this->putJson("/api/conversations/$id/context", ['issue_key' => 'not an issue'])->assertStatus(422);
    $this->putJson("/api/conversations/$id/context", [])->assertStatus(422);
    $this->putJson("/api/conversations/$id/context", ['issue_key' => 'DEV-404'])->assertStatus(404);
    expect(Conversation::find($id)->issue_key)->toBeNull();

    $this->actingAs(yt_user('other', ['email' => 'other-ctx@example.com']));
    $this->putJson("/api/conversations/$id/context", ['issue_key' => 'DEV-7'])->assertNotFound();
});

it('does not link the chat to an issue by itself: creating or editing leaves the context alone', function () {
    ctxFake(['yt.test/api/issues/DEV-7' => Http::response(['id' => '2-7'])]);
    $id = ctxConv($this);
    $this->post("/api/conversations/$id/turn", ['body' => 'erro', 'assistant' => json_encode(['text' => 'ok', 'draft_patch' => [
        'summary' => 'Botão falha', 'description' => 'd', 'project' => 'DEV', 'type' => 'Bug', 'priority' => 'Major',
    ]])])->assertOk();

    // a created issue does not become the context...
    $this->postJson("/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()->assertJsonPath('conversation.context', null);
    // ...and neither does an edited one
    $this->postJson("/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7', 'state' => 'Done'])->assertOk()->assertJsonPath('conversation.context', null);
    expect(Conversation::find($id)->issue_key)->toBeNull();
});

it("keeps the context's title current when its own issue is edited, and never switches it to another issue", function () {
    ctxFake(['yt.test/api/issues/DEV-7' => Http::response(['id' => '2-7'])]);
    $id = ctxConv($this);
    $this->putJson("/api/conversations/$id/context", ['issue_key' => 'DEV-7'])->assertOk();
    Conversation::find($id)->update(['issue_summary' => 'Título antigo']);

    $this->postJson("/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7', 'state' => 'Done'])->assertOk()->assertJsonPath('conversation.context.summary', 'Botão falha');

    $this->postJson("/api/conversations/$id/issues/update", ['issue_id' => 'DEV-9', 'state' => 'Done']);
    expect(Conversation::find($id)->issue_key)->toBe('DEV-7');
});

it("tells Claude which issue the chat is about, with its current fields, and how to close it", function () {
    // the issue is read from YouTrack for every message (it may have changed there)
    ctxFake();
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'fecha o issue']], 'draft_enabled' => true, 'draft' => null, 'catalog' => [], 'context' => ['key' => 'DEV-7']])])->streamedContent();

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'anthropic')) {
            return false;
        }
        $system = $r['system'];

        return str_contains($system, 'linked to the YouTrack issue DEV-7')
            && str_contains($system, '"summary": "Botão falha"') && str_contains($system, '"state": "Open"') && str_contains($system, 'O botão não responde')
            && str_contains($system, 'call `update_issue` with `issue_id` "DEV-7"') && str_contains($system, 'set `state`');
    });
});

it('puts the context in the prompt of both modes, and nothing when the chat has none', function () {
    ctxFake();
    foreach ([true, false] as $draftMode) {
        Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
        $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'x']], 'draft_enabled' => $draftMode, 'catalog' => [], 'context' => ['key' => 'DEV-7']])])->streamedContent();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'], 'linked to the YouTrack issue DEV-7'));
    }

    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'x']], 'draft_enabled' => true, 'catalog' => [], 'context' => null])])->streamedContent();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && ! str_contains($r['system'], 'Conversation context'));
});

it('still gives Claude the key when the context issue cannot be read, and rejects a malformed key', function () {
    ctxFake(['yt.test/api/issues/DEV-7?*' => Http::response(['error' => 'boom'], 500)]);
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);

    $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'fecha']], 'draft_enabled' => true, 'catalog' => [], 'context' => ['key' => 'DEV-7']])])->streamedContent();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'], 'linked to the YouTrack issue DEV-7'));

    $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'x']], 'context' => ['key' => 'x"; drop']])])->assertStatus(422);
});
