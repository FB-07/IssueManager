<?php

use App\Models\Conversation;
use App\Models\IssueDraft;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function call_as(User $user, string $method, string $uri, array $data = [], array $files = [])
{
    return test()->actingAs($user)->call($method, $uri, $data, [], $files, ['HTTP_ACCEPT' => 'application/json']);
}

beforeEach(function () {
    $this->a = yt_user();
    $this->b = yt_user();
    config(['services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'tok', 'services.youtrack.title_prefix' => 'IssueTesteFabio']); // tests must not depend on the prefix chosen in .env
    Cache::flush();
    Storage::fake('local');
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev']]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type'], 'bundle' => ['values' => [['name' => 'Anomaly'], ['name' => 'Task']]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Priority'], 'bundle' => ['values' => [['name' => 'Major'], ['name' => 'Minor']]], '$type' => 'EnumProjectCustomField'],
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([['login' => 'ana', 'fullName' => 'Ana']]),
        'yt.test/api/agiles*' => Http::response([]),
    ]);
});

function turn(User $owner, int $id, array $assistant, string $body = 'pedido', array $files = [])
{
    return call_as($owner, 'POST', "/api/conversations/$id/turn", ['body' => $body, 'assistant' => json_encode($assistant)], $files ? ['files' => $files] : []);
}

it('persists history per browser and lists it on the shell', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->assertCreated()->json('id');
    turn($this->a, $id, ['text' => 'Olá', 'draft_patch' => null])->assertOk()->assertJsonPath('messages.1.body', 'Olá');

    call_as($this->a, 'GET', '/')->assertOk()->assertSee('"title":"pedido"', false);
    call_as($this->b, 'GET', '/')->assertOk()->assertDontSee('"title":"pedido"', false);
    call_as($this->b, 'GET', "/api/conversations/$id")->assertNotFound();
    call_as($this->b, 'DELETE', "/api/conversations/$id")->assertNotFound();
    call_as($this->a, 'GET', "/api/conversations/$id")->assertOk()->assertJsonCount(2, 'messages');
});

it('keeps one draft per conversation, updates it in place and drops invalid values', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');

    turn($this->a, $id, ['text' => 'Rascunho', 'draft_patch' => [
        'summary' => 'Botão falha', 'description' => 'desc 15px', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'INVENTED', 'assignee' => 'nobody',
    ]])->assertOk()->assertJsonPath('draft.type', 'Anomaly')->assertJsonPath('draft.priority', null)->assertJsonPath('draft.assignee', null)
        ->assertJsonPath('draft.revision', 1)->assertJsonPath('messages.1.meta.card', true);

    turn($this->a, $id, ['text' => 'Atualizado', 'draft_patch' => ['priority' => 'Major', 'assignee' => 'ana']])
        ->assertOk()->assertJsonPath('draft.priority', 'Major')->assertJsonPath('draft.assignee', 'ana')
        ->assertJsonPath('draft.summary', 'Botão falha')->assertJsonPath('draft.revision', 2);

    expect(Conversation::find($id)->draft()->count())->toBe(1);
});

it('stores attachments privately and only serves them to their owner', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    $res = turn($this->a, $id, ['text' => 'ok'], 'vê', [UploadedFile::fake()->image('a.png', 10, 10)])->assertOk();
    $url = $res->json('messages.0.attachments.0.url');

    call_as($this->a, 'GET', $url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    call_as($this->b, 'GET', $url)->assertNotFound();
});

it('renames and deletes a conversation (and its files)', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'ok'], 'vê', [UploadedFile::fake()->image('a.png', 10, 10)]);

    call_as($this->a, 'PATCH', "/api/conversations/$id", ['title' => 'Novo nome'])->assertOk()->assertJsonPath('title', 'Novo nome');
    call_as($this->a, 'DELETE', "/api/conversations/$id")->assertNoContent();

    expect(Conversation::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty("attachments/$id");
});

it('creates the issue from the draft only after confirmation, then updates the same issue', function () {
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev']]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Anomaly']]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Major']]], '$type' => 'EnumProjectCustomField'],
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([]),
        'yt.test/api/agiles*' => Http::response([]),
        'yt.test/api/issues/DEV-7*' => Http::response(['id' => '2-7', 'idReadable' => 'DEV-7', 'summary' => 'IssueTesteFabio - Botão falha', 'project' => ['shortName' => 'DEV']]),
        'yt.test/api/issues*' => Http::response(['id' => '2-7', 'idReadable' => 'DEV-7']),
    ]);

    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Botão falha', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major']]);

    call_as($this->a, 'POST', "/api/conversations/$id/draft/create")->assertStatus(422); // not confirmed
    call_as($this->b, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertNotFound();

    $res = call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()
        ->assertJsonPath('draft.status', 'created')->assertJsonPath('draft.issue_key', 'DEV-7')
        ->assertJsonPath('message.kind', 'creation-result')->assertJsonPath('message.meta.key', 'DEV-7');
    expect($res->json('draft.summary'))->toBe('IssueTesteFabio - Botão falha');

    call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()->assertJsonPath('message.meta.updated', true);
});

it('keeps the draft and reports an error when YouTrack rejects the creation', function () {
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev']]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([]),
        'yt.test/api/issues*' => Http::response(['error' => 'x'], 400),
    ]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    $c = Conversation::find($id);
    $c->draft()->create(['summary' => 's', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'revision' => 1]);

    call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertStatus(502)
        ->assertJsonPath('draft.status', 'error')->assertJsonPath('draft.summary', 's');
});

it('deletes several conversations at once, only the ones that belong to the user', function () {
    $mine = [call_as($this->a, 'POST', '/api/conversations')->json('id'), call_as($this->a, 'POST', '/api/conversations')->json('id'), call_as($this->a, 'POST', '/api/conversations')->json('id')];
    $theirs = call_as($this->b, 'POST', '/api/conversations')->json('id');
    turn($this->a, $mine[0], ['text' => 'ok'], 'vê', [UploadedFile::fake()->image('a.png', 10, 10)]);

    $res = call_as($this->a, 'DELETE', '/api/conversations', ['ids' => [$mine[0], $mine[1], $theirs, 99999]])->assertOk();

    expect($res->json('deleted'))->toEqualCanonicalizing([$mine[0], $mine[1]])
        ->and(Conversation::pluck('id')->all())->toEqualCanonicalizing([$mine[2], $theirs]);
    Storage::disk('local')->assertDirectoryEmpty("attachments/{$mine[0]}");

    call_as($this->a, 'DELETE', '/api/conversations', ['ids' => []])->assertStatus(422);
    call_as($this->a, 'DELETE', '/api/conversations', ['ids' => ['x']])->assertStatus(422);
});

it('direct turns (draft mode off) skip the card when the draft is complete and keep it when something is missing', function () {
    $full = ['summary' => 'Botão falha', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major'];

    $a = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $a, ['text' => 'A criar', 'draft_patch' => $full, 'direct' => true])->assertOk()
        ->assertJsonPath('messages.1.meta.direct', true)->assertJsonMissingPath('messages.1.meta.card');

    $b = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $b, ['text' => 'Falta', 'draft_patch' => ['summary' => 'x', 'description' => 'y', 'project' => 'DEV'], 'direct' => true])->assertOk()
        ->assertJsonPath('messages.1.meta.card', true)->assertJsonMissingPath('messages.1.meta.direct');
});

it('stores a read-only preview and the direct flag in the creation result', function () {
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev']]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Anomaly']]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Major']]], '$type' => 'EnumProjectCustomField'],
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([]),
        'yt.test/api/agiles*' => Http::response([]),
        'yt.test/api/issues*' => Http::response(['id' => '2-8', 'idReadable' => 'DEV-8']),
    ]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Botão falha', 'description' => '15px', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major', 'labels' => ['pagamentos']], 'direct' => true]);

    call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true, 'direct' => true])->assertOk()
        ->assertJsonPath('message.meta.direct', true)->assertJsonPath('message.meta.preview.description', '15px')
        ->assertJsonPath('message.meta.preview.type', 'Anomaly')->assertJsonPath('message.meta.preview.labels.0', 'pagamentos');
});

function fake_yt_for_edit(string $summary = 'IssueTesteFabio - Botão falha'): void
{
    Http::swap(new Factory); // drop the stubs of beforeEach: the first matching stub wins
    Http::fake([
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev']]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Anomaly'], ['name' => 'Task']]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Major'], ['name' => 'Minor']]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'State', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Open'], ['name' => 'Closed']]], '$type' => 'StateProjectCustomField'],
            ['field' => ['name' => 'Assignee', 'fieldType' => ['isMultiValue' => true]], '$type' => 'UserProjectCustomField'],
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([['login' => 'fabio', 'fullName' => 'Fábio']]),
        'yt.test/api/agiles*' => Http::response([]),
        'yt.test/api/issues/DEV-7?*' => Http::response(['id' => '2-7', 'idReadable' => 'DEV-7', 'summary' => $summary, 'description' => 'novo texto', 'project' => ['shortName' => 'DEV'],
            'customFields' => [['name' => 'Type', 'value' => ['name' => 'Task']], ['name' => 'Assignee', 'value' => ['fullName' => 'Fábio']]]]),
        'yt.test/api/issues/DEV-7' => Http::response(['id' => '2-7']),
    ]);
}

it('edits an existing issue from the chat, keeps the title prefix, ignores invalid values and syncs the draft', function () {
    fake_yt_for_edit();
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    Conversation::find($id)->draft()->create(['summary' => 'IssueTesteFabio - Botão falha', 'description' => 'antigo', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major',
        'status' => 'created', 'issue_key' => 'DEV-7', 'issue_url' => 'https://yt.test/issue/DEV-7', 'revision' => 3]);

    $res = call_as($this->a, 'POST', "/api/conversations/$id/issues/update", [
        'issue_id' => 'DEV-7', 'summary' => 'Texto do botão maior', 'description' => 'novo texto', 'type' => 'Task',
        'priority' => 'INVENTED', 'assignee' => 'fabio', 'state' => 'Open',
    ])->assertOk()
        ->assertJsonPath('message.kind', 'creation-result')->assertJsonPath('message.meta.updated', true)->assertJsonPath('message.meta.key', 'DEV-7')
        ->assertJsonPath('message.meta.preview.assignee', 'Fábio')->assertJsonPath('draft.type', 'Task')->assertJsonPath('draft.revision', 4);

    expect($res->json('message.meta.changes'))->toEqualCanonicalizing(['summary', 'description', 'type', 'assignee', 'state']);

    Http::assertSent(function ($r) {
        if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/api/issues/DEV-7?fields=id')) {
            return false;
        }
        $cf = collect($r['customFields'])->keyBy('name');

        return $r['summary'] === 'IssueTesteFabio - Texto do botão maior' // prefix kept: the issue was created by the app
            && $r['description'] === 'novo texto'
            && $cf['Type']['value'] === ['name' => 'Task']
            && $cf['Assignee']['$type'] === 'MultiUserIssueCustomField' && $cf['Assignee']['value'] === [['login' => 'fabio']]
            && $cf['State']['$type'] === 'StateIssueCustomField'
            && ! $cf->has('Priority'); // the invented priority was dropped
    });
});

it('does not add the prefix when editing an issue the app did not create, and rejects bad requests', function () {
    fake_yt_for_edit('Issue antigo de outra pessoa');
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');

    call_as($this->a, 'POST', "/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7', 'summary' => 'Novo título'])->assertOk();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/issues/DEV-7?fields=id') && $r['summary'] === 'Novo título');

    call_as($this->a, 'POST', "/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7', 'priority' => 'INVENTED'])->assertStatus(422);
    call_as($this->a, 'POST', "/api/conversations/$id/issues/update", ['issue_id' => '../../etc'])->assertStatus(422);
    call_as($this->b, 'POST', "/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7', 'summary' => 'x'])->assertNotFound();
});

function fake_yt_for_create(array $extra = []): void
{
    Http::swap(new Factory);
    Http::fake($extra + [
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'DEV', 'name' => 'Dev']]),
        'yt.test/api/admin/projects/0-1/customFields*' => Http::response([
            ['field' => ['name' => 'Type', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Anomaly']]], '$type' => 'EnumProjectCustomField'],
            ['field' => ['name' => 'Priority', 'fieldType' => ['isMultiValue' => false]], 'bundle' => ['values' => [['name' => 'Major']]], '$type' => 'EnumProjectCustomField'],
        ]),
        'yt.test/api/admin/projects/0-1/team/users*' => Http::response([]),
        'yt.test/api/agiles*' => Http::response([]),
        'yt.test/api/issues/DEV-8/attachments*' => Http::response([['id' => '1-1', 'name' => 'ok']]),
        'yt.test/api/issues?*' => Http::response(['id' => '2-8', 'idReadable' => 'DEV-8']),
    ]);
}

it('uploads the selected conversation images to the new issue and marks them as sent', function () {
    fake_yt_for_create();
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    $res = turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Botão', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major']], 'vê', [
        UploadedFile::fake()->image('erro.png', 20, 20), UploadedFile::fake()->image('ignorar.png', 20, 20),
    ])->assertOk();
    [$keep, $skip] = [$res->json('messages.0.attachments.0'), $res->json('messages.0.attachments.1')];
    expect($keep['to_issue'])->toBeTrue()->and($keep['uploaded_to'])->toBeNull();

    call_as($this->a, 'PATCH', "/api/attachments/{$skip['id']}", ['to_issue' => false])->assertOk()->assertJsonPath('to_issue', false);
    call_as($this->b, 'PATCH', "/api/attachments/{$skip['id']}", ['to_issue' => true])->assertNotFound();

    $created = call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()
        ->assertJsonPath('message.meta.attachments', ['erro.png'])->assertJsonPath('message.meta.attachments_failed', []);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/issues/DEV-8/attachments') && $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r->body(), 'erro.png') && ! str_contains($r->body(), 'ignorar.png'));
    expect(DB::table('attachments')->where('id', $keep['id'])->value('youtrack_issue'))->toBe('DEV-8')
        ->and(DB::table('attachments')->where('id', $skip['id'])->value('youtrack_issue'))->toBeNull();

    // saving again does not upload the same file twice
    fake_yt_for_create(['yt.test/api/issues/DEV-8?*' => Http::response(['id' => '2-8', 'idReadable' => 'DEV-8', 'summary' => 'x', 'project' => ['shortName' => 'DEV'], 'customFields' => []]), 'yt.test/api/issues/DEV-8' => Http::response(['id' => '2-8'])]);
    call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()->assertJsonPath('message.meta.attachments', []);
});

it('still creates the issue when an attachment upload fails and reports it', function () {
    fake_yt_for_create(['yt.test/api/issues/DEV-8/attachments*' => Http::response(['error' => 'too big'], 413)]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Botão', 'description' => 'd', 'project' => 'DEV', 'type' => 'Anomaly', 'priority' => 'Major']], 'vê', [UploadedFile::fake()->image('grande.png', 20, 20)]);

    call_as($this->a, 'POST', "/api/conversations/$id/draft/create", ['confirmed' => true])->assertOk()
        ->assertJsonPath('draft.status', 'created')->assertJsonPath('message.meta.attachments', [])->assertJsonPath('message.meta.attachments_failed', ['grande.png']);
});

it('attaches the images of the last message when editing an existing issue', function () {
    fake_yt_for_edit();
    Http::fake(['yt.test/api/issues/DEV-7/attachments*' => Http::response([['id' => '1-9']])]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'a anexar'], 'anexa esta imagem ao DEV-7', [UploadedFile::fake()->image('print.png', 20, 20)]);

    call_as($this->a, 'POST', "/api/conversations/$id/issues/update", ['issue_id' => 'DEV-7'])->assertOk()
        ->assertJsonPath('message.meta.attachments', ['print.png'])->assertJsonPath('message.meta.changes', []);
});

it('stores sanitized quick replies in the assistant message and returns them when the conversation is reopened', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');

    turn($this->a, $id, ['text' => 'Em que ambiente acontece?', 'replies' => [
        ['label' => 'Produção'], ['label' => 'Staging', 'kind' => 'bogus'], ['label' => 'x', 'kind' => 'person', 'value' => 'fabio'], ['label' => 'A'], ['label' => 'B'],
    ]])->assertOk()->assertJsonCount(4, 'messages.1.meta.replies')->assertJsonPath('messages.1.meta.replies.1.kind', 'text');

    $shown = call_as($this->a, 'GET', "/api/conversations/$id")->assertOk();
    expect(array_column($shown->json('messages.1.meta.replies'), 'label'))->toBe(['Produção', 'Staging', 'x', 'A']);

    // a normal answer carries no replies at all
    $id2 = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id2, ['text' => 'ok'])->assertOk()->assertJsonMissingPath('messages.1.meta.replies');
});

it('discards the draft of a conversation, only for its owner and never one already created', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Botão falha']]);

    call_as($this->b, 'DELETE', "/api/conversations/$id/draft")->assertNotFound();
    expect(call_as($this->a, 'GET', "/api/conversations/$id")->json('draft.summary'))->toBe('Botão falha');

    call_as($this->a, 'DELETE', "/api/conversations/$id/draft")->assertNoContent();
    expect(call_as($this->a, 'GET', "/api/conversations/$id")->json('draft'))->toBeNull();
    call_as($this->a, 'DELETE', "/api/conversations/$id/draft")->assertNotFound();

    turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Outro']]);
    IssueDraft::where('conversation_id', $id)->update(['status' => 'created']);
    call_as($this->a, 'DELETE', "/api/conversations/$id/draft")->assertStatus(409);
});

it('starts a fresh draft when the model asks for another issue, and leaves the created one alone', function () {
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Primeiro']]);
    IssueDraft::where('conversation_id', $id)->update(['status' => 'created', 'issue_key' => 'DEV-1']);

    // without the flag the patch edits the created issue's draft, as before
    $r = turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['summary' => 'Primeiro, corrigido']]);
    expect($r->json('draft.issue_key'))->toBe('DEV-1')->and($r->json('draft.summary'))->toBe('Primeiro, corrigido');

    // with it, a new draft replaces the finished one
    $r = turn($this->a, $id, ['text' => 'ok', 'draft_patch' => ['new_issue' => true, 'summary' => 'Segundo']]);
    expect($r->json('draft.summary'))->toBe('Segundo')->and($r->json('draft.issue_key'))->toBeNull()->and($r->json('draft.status'))->toBe('draft');
    expect(IssueDraft::where('conversation_id', $id)->count())->toBe(1);
});

it('posts a comment the user asked for on an existing issue and records it in the conversation', function () {
    fake_yt_for_edit();
    Http::fake(['yt.test/api/issues/DEV-7/comments*' => Http::response(['id' => '4-1', 'created' => 1])]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');

    $res = call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => '  Já foi testado.  '])->assertOk()
        ->assertJsonPath('message.kind', 'creation-result')->assertJsonPath('message.meta.commented', true)->assertJsonPath('message.meta.key', 'DEV-7')
        ->assertJsonPath('message.meta.comment', 'Já foi testado.');
    expect($res->json('conversation.id'))->toBe($id);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/api/issues/DEV-7/comments') && $r['text'] === 'Já foi testado.');

    call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => '   '])->assertStatus(422);
    call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => '../../etc', 'text' => 'x'])->assertStatus(422);
    call_as($this->b, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => 'x'])->assertNotFound();
});

it('edits a comment the app posted, in place, and refuses one it did not post', function () {
    fake_yt_for_edit();
    Http::fake(['yt.test/api/issues/DEV-7/comments*' => Http::response(['id' => '4-1', 'created' => 1])]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    $card = call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => 'Versão 1'])->assertOk()
        ->assertJsonPath('message.meta.comment_id', '4-1');

    call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => 'Versão 2', 'comment_id' => '4-1', 'in_place' => true])->assertOk()
        ->assertJsonPath('message.id', $card->json('message.id'))->assertJsonPath('message.meta.comment', 'Versão 2')->assertJsonPath('message.meta.edited', true);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/issues/DEV-7/comments/4-1') && $r['text'] === 'Versão 2');

    Http::swap(new \Illuminate\Http\Client\Factory);
    fake_yt_for_edit();
    Http::fake(['yt.test/api/issues/DEV-7/comments*' => Http::response([]), 'yt.test/api/users/me*' => Http::response(['login' => 'me'])]);
    call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => 'x', 'comment_id' => '9-9'])->assertNotFound();
});

it('gives a comment edited from the chat a card of its own and keeps the old card in sync', function () {
    fake_yt_for_edit();
    Http::fake(['yt.test/api/issues/DEV-7/comments*' => Http::response(['id' => '4-1', 'created' => 1])]);
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    $card = call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => 'Versão 1'])->assertOk();

    $res = call_as($this->a, 'POST', "/api/conversations/$id/issues/comment", ['issue_id' => 'DEV-7', 'text' => 'Versão 2', 'comment_id' => '4-1'])->assertOk()
        ->assertJsonPath('message.meta.edited', true)->assertJsonPath('original.id', $card->json('message.id'))->assertJsonPath('original.meta.comment', 'Versão 2');
    expect($res->json('message.id'))->not->toBe($card->json('message.id'));
});

it('takes back the last exchange, restoring the draft it changed, and refuses once something depends on it', function () {
    fake_yt_for_edit();
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');

    // first turn creates the draft; the second one changes it
    call_as($this->a, 'POST', "/api/conversations/$id/turn", ['body' => 'um erro', 'assistant' => json_encode(['text' => 'Rascunho', 'draft_patch' => ['summary' => 'Erro A', 'description' => 'd', 'project' => 'DEV', 'type' => 'Task', 'priority' => 'Major']])])->assertOk();
    call_as($this->a, 'POST', "/api/conversations/$id/turn", ['body' => 'muda o título', 'assistant' => json_encode(['text' => 'Mudei', 'draft_patch' => ['summary' => 'Erro B']])])->assertOk();
    expect(IssueDraft::where('conversation_id', $id)->first()->summary)->toBe('Erro B');

    $res = call_as($this->a, 'DELETE', "/api/conversations/$id/messages/last")->assertOk()
        ->assertJsonPath('removed.body', 'muda o título')->assertJsonPath('draft.summary', 'Erro A');
    expect($res->json('removed.role'))->toBe('user')->and(Message::where('conversation_id', $id)->count())->toBe(2);

    // taking back the first exchange removes the draft it created
    call_as($this->a, 'DELETE', "/api/conversations/$id/messages/last")->assertOk()->assertJsonPath('draft', null);
    expect(IssueDraft::where('conversation_id', $id)->count())->toBe(0)->and(Message::where('conversation_id', $id)->count())->toBe(0);

    // nothing left, and somebody else's conversation is not reachable
    call_as($this->a, 'DELETE', "/api/conversations/$id/messages/last")->assertStatus(409);
    call_as($this->b, 'DELETE', "/api/conversations/$id/messages/last")->assertNotFound();
});

it('does not take back an answer that already did something in YouTrack', function () {
    fake_yt_for_edit();
    $id = call_as($this->a, 'POST', '/api/conversations')->json('id');
    call_as($this->a, 'POST', "/api/conversations/$id/turn", ['body' => 'cria', 'assistant' => json_encode(['text' => 'Rascunho', 'draft_patch' => ['summary' => 'Erro A', 'description' => 'd', 'project' => 'DEV', 'type' => 'Task', 'priority' => 'Major']])])->assertOk();
    // a card of what the app did afterwards (an issue created) comes after the answer: the last message is no longer the answer
    Message::create(['conversation_id' => $id, 'role' => 'assistant', 'kind' => 'creation-result', 'meta' => ['key' => 'DEV-1']]);

    call_as($this->a, 'DELETE', "/api/conversations/$id/messages/last")->assertStatus(409);
});
