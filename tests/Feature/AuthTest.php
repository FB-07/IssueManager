<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.youtrack.hub_url' => 'https://hub.test/hub', 'services.youtrack.client_id' => 'cid',
        'services.youtrack.client_secret' => 'secret', 'services.youtrack.redirect' => 'http://issuemanager.test/auth/youtrack/callback',
        'services.youtrack.dev_login' => false,
    ]);
});

function hub_login(string $id = 'hub-1', ?string $email = 'ana@proside.pt', array $cookies = [])
{
    Http::fake([
        'hub.test/hub/api/rest/oauth2/token' => Http::response(['access_token' => 'at']),
        'hub.test/hub/api/rest/users/me*' => Http::response(['id' => $id, 'name' => 'Ana Silva', 'profile' => ['email' => ['email' => $email], 'avatar' => ['url' => 'https://a/x.png']]]),
    ]);

    return test()->withSession(['oauth_state' => 'st'])->call('GET', '/auth/youtrack/callback', ['code' => 'abc', 'state' => 'st'], $cookies);
}

it('sends guests to the login page and API callers get 401', function () {
    $this->get('/')->assertRedirect('/login');
    $this->getJson('/api/youtrack/projects')->assertUnauthorized();
    $this->postJson('/api/chat')->assertUnauthorized();
    $this->get('/login')->assertOk()->assertSee('Entrar com YouTrack');
});

it('redirects to the Hub with state and the registered redirect uri', function () {
    $res = $this->get('/auth/youtrack/redirect');
    $loc = $res->headers->get('Location');

    expect($loc)->toStartWith('https://hub.test/hub/api/rest/oauth2/auth?')->toContain('client_id=cid')->toContain('response_type=code')
        ->toContain(rawurlencode('http://issuemanager.test/auth/youtrack/callback'));
    expect(session('oauth_state'))->toBeString()->and($loc)->toContain(session('oauth_state'));
});

it('logs in an invited account, links it to the Hub id and claims legacy history', function () {
    $user = User::factory()->create(['email' => 'Ana@Proside.pt', 'hub_id' => null]);
    $legacy = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    Conversation::create(['owner' => $legacy, 'title' => 'antiga']);

    $enc = app('encrypter');
    $cookie = $enc->encrypt(CookieValuePrefix::create('im_owner', $enc->getKey()).$legacy, false);

    hub_login('hub-1', 'ana@proside.pt', ['im_owner' => $cookie])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->hub_id)->toBe('hub-1')->and($user->fresh()->name)->toBe('Ana Silva')
        ->and(Conversation::first()->user_id)->toBe($user->id);
});

it('denies accounts that were not invited', function () {
    hub_login('hub-9', 'stranger@x.com')->assertRedirect('/login')->assertSessionHas('error');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('rejects a wrong state and a failed token exchange', function () {
    $this->withSession(['oauth_state' => 'st'])->get('/auth/youtrack/callback?code=abc&state=other')->assertRedirect('/login');
    Http::fake(['hub.test/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    User::factory()->create(['email' => 'ana@proside.pt']);
    $this->withSession(['oauth_state' => 'st'])->get('/auth/youtrack/callback?code=bad&state=st')->assertRedirect('/login')->assertSessionHas('error');
    $this->assertGuest();
});

it('only honours the dev login when enabled in a local environment', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->post('/auth/dev')->assertNotFound();
    config(['services.youtrack.dev_login' => true]);
    app()['env'] = 'production';
    $this->post('/auth/dev')->assertNotFound();
    app()['env'] = 'local';
    $this->post('/auth/dev')->assertRedirect();
    $this->assertAuthenticated();
});

it('stores theme and language per user and logs out', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/preferences', ['theme' => 'light', 'locale' => 'en'])->assertOk();
    expect($user->fresh()->only('theme', 'locale'))->toBe(['theme' => 'light', 'locale' => 'en']);
    $this->actingAs($user)->patchJson('/api/preferences', ['theme' => 'pink'])->assertStatus(422);
    $this->actingAs($user)->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('invites and removes accounts from the command line', function () {
    $this->artisan('issuemanager:invite', ['email' => 'New@Proside.pt', '--name' => 'New'])->assertSuccessful();
    expect(User::where('email', 'new@proside.pt')->exists())->toBeTrue();
    $this->artisan('issuemanager:invite', ['email' => 'new@proside.pt', '--remove' => true])->expectsConfirmation('Delete new@proside.pt and all their conversations?', 'yes')->assertSuccessful();
    expect(User::count())->toBe(0);
});
