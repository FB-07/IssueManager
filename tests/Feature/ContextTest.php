<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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

function ctxTurn($test, int $id, string $text, ?array $issues = null, ?string $raw = null)
{
    $data = ['body' => $text, 'assistant' => json_encode(['text' => 'ok'])];
    if ($issues !== null || $raw !== null) {
        $data['contexts'] = $raw ?? json_encode($issues);
    }

    return $test->post("/api/conversations/$id/turn", $data)->assertOk();
}

function ctxChat($test, array $over = []): void
{
    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $test->post('/api/chat', ['payload' => json_encode($over + ['messages' => [['role' => 'user', 'content' => 'x']], 'draft_enabled' => true, 'draft' => null, 'catalog' => []])])->streamedContent();
}

function ctxSystem(): string
{
    return Http::recorded(fn ($r) => str_contains($r->url(), 'anthropic'))->first()[0]['system'];
}

it('ties nothing to the chat: creating or editing an issue leaves no issue stored on the conversation', function () {
    ctxFake(['yt.test/api/issues/DEV-7' => Http::response(['id' => '2-7'])]);
    $id = ctxConv($this);
    $this->post("/api/conversations/$id/turn", ['body' => 'erro', 'assistant' => json_encode(['text' => 'ok', 'draft_patch' => [
        'summary' => 'Botão falha', 'description' => 'd', 'project' => 'DEV', 'type' => 'Bug', 'priority' => 'Major',
    ]])])->assertOk();

    $this->postJson("/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()->assertJsonMissingPath('conversation.context');
    $this->postJson("/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7', 'state' => 'Done'])->assertOk()->assertJsonMissingPath('conversation.context');
    $this->getJson("/api/conversations/$id")->assertOk()->assertJsonMissingPath('conversation.context');
    expect(Schema::hasColumn('conversations', 'issue_key'))->toBeFalse();
});

it('keeps the issues attached to a message with that message, like its images: each message has its own', function () {
    $id = ctxConv($this);

    $a = ctxTurn($this, $id, 'altera a prioridade', [['key' => 'DEV-7', 'summary' => 'Botão falha']])->json('messages.0');
    $b = ctxTurn($this, $id, 'fecha estes', [['key' => 'DEV-12', 'summary' => 'Quiosque'], ['key' => 'DEV-15', 'summary' => 'Faturas']])->json('messages.0');
    $c = ctxTurn($this, $id, 'e uma pergunta solta')->json('messages.0');

    expect($a['meta']['contexts'])->toBe([['key' => 'DEV-7', 'summary' => 'Botão falha']])->and(array_column($b['meta']['contexts'], 'key'))->toBe(['DEV-12', 'DEV-15'])->and((array) $c['meta'])->not->toHaveKey('contexts');
    $stored = collect($this->getJson("/api/conversations/$id")->json('messages'))->where('role', 'user')->map(fn ($m) => array_column($m['meta']['contexts'] ?? [], 'key'))->values()->all();
    expect($stored)->toBe([['DEV-7'], ['DEV-12', 'DEV-15'], []]);
});

it('ignores malformed issues, repeats and anything over five, and cuts a long summary', function () {
    $id = ctxConv($this);

    expect((array) ctxTurn($this, $id, 'a', raw: '[{"key":"não é uma chave"}]')->json('messages.0.meta'))->not->toHaveKey('contexts');
    expect((array) ctxTurn($this, $id, 'b', raw: 'isto nem é json')->json('messages.0.meta'))->not->toHaveKey('contexts');
    expect((array) ctxTurn($this, $id, 'c', raw: '[{"summary":"sem chave"}]')->json('messages.0.meta'))->not->toHaveKey('contexts');

    $many = array_map(fn ($n) => ['key' => 'DEV-'.($n % 7 + 1), 'summary' => str_repeat('x', 300)], range(1, 12)); // repeats too
    $stored = ctxTurn($this, $id, 'd', $many)->json('messages.0.meta.contexts');
    expect(count($stored))->toBeLessThanOrEqual(5)->and(array_column($stored, 'key'))->toBe(array_values(array_unique(array_column($stored, 'key'))))
        ->and(mb_strlen($stored[0]['summary']))->toBeLessThanOrEqual(200);
});

it('gives Claude the issue attached to this message, with its current fields, for this message only', function () {
    ctxFake(); // the issue is read from YouTrack for every message (it may have changed there)
    ctxChat($this, ['contexts' => [['key' => 'DEV-7']]]);

    $system = ctxSystem();
    expect($system)->toContain('the user attached the YouTrack issue DEV-7 as context, like an image')->toContain('belongs to THIS message only')
        ->toContain('their latest message starts with the tag `[Contexto: DEV-7]`')->toContain('ASSUME it is the issue meant')
        ->toContain('"summary":"Botão falha"')->toContain('"state":"Open"')->toContain('O botão não responde')
        ->toContain('call `update_issue` with `issue_id` "DEV-7"')->toContain('set `state`')->not->toContain('linked to')->not->toContain('in the same chat');
});

it('gives Claude several attached issues, one per line, and how to act on them', function () {
    ctxFake(['yt.test/api/issues/DEV-12?*' => Http::response(['id' => '2-12', 'idReadable' => 'DEV-12', 'summary' => 'Quiosque', 'description' => '', 'project' => ['shortName' => 'DEV'], 'customFields' => [['name' => 'State', 'value' => ['name' => 'In Progress']]]])]);
    ctxChat($this, ['contexts' => [['key' => 'DEV-7'], ['key' => 'DEV-12'], ['key' => 'DEV-7']]]); // a repeated key is read once

    $system = ctxSystem();
    expect($system)->toContain('these YouTrack issues as context: DEV-7, DEV-12')->toContain('`[Contexto: DEV-7, DEV-12]`')
        ->toContain('"summary":"Botão falha"')->toContain('"summary":"Quiosque"')->toContain('"state":"In Progress"')
        ->toContain('on ALL of them when the user says so (one `update_issue` call each)')->not->toContain('ASSUME it is the issue meant');
});

it('explains the tags of earlier messages in both modes, with or without issues, so an old tag is never carried over', function () {
    ctxFake();
    foreach ([true, false] as $draftMode) {
        foreach ([[], [['key' => 'DEV-7']]] as $issues) {
            ctxChat($this, ['messages' => [['role' => 'user', 'content' => '[Contexto: DEV-3] altera isto'], ['role' => 'assistant', 'content' => 'ok'], ['role' => 'user', 'content' => 'e o issue?']], 'draft_enabled' => $draftMode, 'contexts' => $issues]);

            expect(ctxSystem())->toContain('`[Contexto: DEV-3]`')->toContain('those issues belong to THAT message only')->toContain('Never carry a tag over to a later message')->toContain('for any CHANGE to an issue (edit, assign, close, reopen) never assume one from earlier messages or tags')->toContain('for a plain QUESTION you may follow the conversation');
        }
    }
});

it('tells Claude, when no issue is attached, not to change an issue discussed earlier', function () {
    foreach ([true, false] as $draftMode) {
        ctxChat($this, ['contexts' => [], 'draft_enabled' => $draftMode]);
        expect(ctxSystem())->not->toContain('Issue context: with this message')->toContain("No YouTrack issue is attached to the user's latest message")
            ->toContain('do NOT pick the one discussed earlier and do NOT call any update')->toContain('attach it to the message with the paperclip');
    }
    ctxChat($this, ['contexts' => [['key' => 'DEV-7']]]);
    expect(ctxSystem())->not->toContain('No YouTrack issue is attached');
});

it('still gives Claude the key of an attached issue that cannot be read, and rejects malformed keys and too many issues', function () {
    ctxFake(['yt.test/api/issues/DEV-7?*' => Http::response(['error' => 'boom'], 500)]);
    ctxChat($this, ['contexts' => [['key' => 'DEV-7']]]);
    expect(ctxSystem())->toContain('the user attached the YouTrack issue DEV-7 as context');

    $bad = fn ($contexts) => $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'x']], 'contexts' => $contexts])]);
    $bad([['key' => 'x"; drop']])->assertStatus(422);
    $bad([['summary' => 'sem chave']])->assertStatus(422);
    $bad(array_map(fn ($n) => ['key' => "DEV-$n"], range(1, 6)))->assertStatus(422);
});

it('knows which states mean "done" (the ones YouTrack flags as resolved) and tells Claude to close with them', function () {
    ctxFake([
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'State', 'fieldType' => ['isMultiValue' => false]], '$type' => 'StateProjectCustomField',
                'bundle' => ['values' => [['name' => 'Open', 'isResolved' => false], ['name' => 'Completed', 'isResolved' => true], ['name' => 'Closed', 'isResolved' => true], ['name' => 'Testing']]]],
        ]),
    ]);

    $meta = $this->getJson('/api/youtrack/projects/DEV/meta')->assertOk()->json();
    expect($meta['states'])->toBe(['Open', 'Completed', 'Closed', 'Testing'])->and($meta['resolved_states'])->toBe(['Completed', 'Closed']);

    Http::fake(['api.anthropic.com/*' => Http::response("event: message_stop\ndata: {}\n\n", 200)]);
    $this->post('/api/chat', ['payload' => json_encode(['messages' => [['role' => 'user', 'content' => 'fecha o issue']], 'draft_enabled' => true, 'catalog' => ['byProject' => ['DEV' => $meta]], 'contexts' => [['key' => 'DEV-7']]])])->streamedContent();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'], '`resolved_states`') && str_contains($r['system'], '"fecha"/"fechar"/"close" -> the one named Closed')
        && str_contains($r['system'], 'Ask (with quick replies) only if the wording fits two or more') && str_contains($r['system'], '"resolved_states":["Completed","Closed"]'));
});
