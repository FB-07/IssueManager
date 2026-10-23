<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.youtrack.hub_url' => 'https://hub.test/hub', 'services.youtrack.client_id' => 'cid',
        'services.youtrack.client_secret' => 'secret', 'services.youtrack.redirect' => 'http://issuemanager.test/auth/youtrack/callback',
        'services.youtrack.dev_login' => false, 'services.youtrack.url' => 'https://yt.test',
    ]);
});

function hub_login(string $id = 'hub-1', ?string $email = 'ana@proside.pt', array $cookies = [], bool $youtrackAccess = true)
{
    Http::fake([
        'hub.test/hub/api/rest/oauth2/token' => Http::response(['access_token' => 'at-'.$id, 'refresh_token' => 'rt-'.$id, 'expires_in' => 3600, 'scope' => '0-0-0-0-0 yt']),
        'yt.test/api/users/me*' => $youtrackAccess ? Http::response(['id' => '1-1', 'login' => 'ana']) : Http::response(['error' => 'forbidden'], 403),
        'hub.test/hub/api/rest/users/me*' => Http::response(['id' => $id, 'name' => 'Ana Silva', 'profile' => ['email' => ['email' => $email], 'avatar' => ['url' => 'https://a/x.png']]]),
    ]);

    return test()->withSession(['oauth_state' => 'st'])->call('GET', '/auth/youtrack/callback', ['code' => 'abc', 'state' => 'st'], $cookies);
}

it('sends guests to the login page and API callers get 401', function () {
    $this->get('/')->assertRedirect('/login');
    $this->getJson('/api/youtrack/projects')->assertUnauthorized();
    $this->postJson('/api/chat')->assertUnauthorized();
    $this->get('/login')->assertOk()->assertSee('Entrar com YouTrack')->assertSee('contas @proside.pt');
});

it('redirects to the Hub with state and the registered redirect uri', function () {
    $res = $this->get('/auth/youtrack/redirect');
    $loc = $res->headers->get('Location');

    expect($loc)->toStartWith('https://hub.test/hub/api/rest/oauth2/auth?')->toContain('client_id=cid')->toContain('response_type=code')
        ->toContain(rawurlencode('http://issuemanager.test/auth/youtrack/callback'));
    expect(session('oauth_state'))->toBeString()->and($loc)->toContain(session('oauth_state'));
});

it('logs in an existing account, links it to the Hub id and claims legacy history', function () {
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

it('creates the user on the first login of an account of the allowed domain, without any invitation', function () {
    expect(User::count())->toBe(0);

    hub_login('hub-5', 'Nova.Pessoa@PROSIDE.pt')->assertRedirect(route('home'));

    $user = User::first();
    expect(User::count())->toBe(1)->and($user->email)->toBe('nova.pessoa@proside.pt')->and($user->hub_id)->toBe('hub-5')->and($user->name)->toBe('Ana Silva');
    $this->assertAuthenticatedAs($user);
    expect(DB::table('youtrack_connections')->where('user_id', $user->id)->count())->toBe(1);

    // the next login finds the same user instead of creating another one
    auth()->logout();
    hub_login('hub-5', 'nova.pessoa@proside.pt')->assertRedirect(route('home'));
    expect(User::count())->toBe(1);
});

it('denies accounts outside the allowed domain, including look-alike domains and accounts without email', function () {
    foreach (['stranger@x.com', 'ana@proside.pt.evil.com', 'ana@evilproside.pt', 'ana@sub.proside.pt', null] as $i => $email) {
        hub_login("hub-$i", $email)->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }
    expect(User::count())->toBe(0)->and(DB::table('youtrack_connections')->count())->toBe(0);
    expect(session('error'))->toContain('proside.pt');
});

it('honours a different allowed domain from the configuration', function () {
    config(['issuemanager.allowed_domains' => ['outra.pt']]);

    hub_login('hub-1', 'ana@proside.pt')->assertRedirect('/login');
    $this->assertGuest();

    Http::swap(new \Illuminate\Http\Client\Factory); // the first stubs would win otherwise
    hub_login('hub-2', 'ana@outra.pt')->assertRedirect(route('home'));
});

it('accepts every domain of the list and none of their look-alikes', function () {
    config(['issuemanager.allowed_domains' => ['proside.pt', 'empirepuzzle.com']]);

    hub_login('hub-1', 'ana@empirepuzzle.com')->assertRedirect(route('home'));
    auth()->logout();
    Http::swap(new \Illuminate\Http\Client\Factory);
    hub_login('hub-2', 'rui@empirepuzzle.com.evil.com')->assertRedirect('/login')->assertSessionHas('error');
    expect(session('error'))->toContain('proside.pt, @empirepuzzle.com');
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

it('always sends the redirect uri built from APP_URL, even if the page was opened over a different scheme', function () {
    config(['services.youtrack.redirect' => null, 'app.url' => 'http://issuemanager.test']);

    $loc = $this->get('https://issuemanager.test/auth/youtrack/redirect')->headers->get('Location');

    expect($loc)->toContain('redirect_uri='.rawurlencode('http://issuemanager.test/auth/youtrack/callback'));
});

it('stores the Hub token encrypted and checks YouTrack access on login', function () {
    $user = User::factory()->create(['email' => 'ana@proside.pt']);
    hub_login('hub-1', 'ana@proside.pt')->assertRedirect(route('home'));

    $conn = $user->fresh()->youtrackConnection;
    expect($conn->access_token)->toBe('at-hub-1')->and($conn->refresh_token)->toBe('rt-hub-1');
    expect(DB::table('youtrack_connections')->value('access_token'))->not->toContain('at-hub-1');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'yt.test/api/users/me') && $r->hasHeader('Authorization', 'Bearer at-hub-1'));
});

it('refuses the login when the account has no access to YouTrack', function () {
    $user = User::factory()->create(['email' => 'ana@proside.pt']);
    hub_login('hub-1', 'ana@proside.pt', [], youtrackAccess: false)->assertRedirect('/login')->assertSessionHas('error');

    $this->assertGuest();
    expect($user->fresh()->youtrackConnection)->toBeNull();
});

it('asks the Hub for credentials again only when the user wants to use another account', function () {
    expect($this->get('/auth/youtrack/redirect')->headers->get('Location'))->toContain('request_credentials=default');
    expect($this->get('/auth/youtrack/redirect?other=1')->headers->get('Location'))->toContain('request_credentials=required');
});

it('refuses to sign in as a different account than the remembered one the user picked', function () {
    User::factory()->create(['email' => 'ana@proside.pt']);
    User::factory()->create(['email' => 'rui@proside.pt']);

    // the user picked Rui on the login page, but the Hub session belongs to Ana
    $this->get('/auth/youtrack/redirect?account=Rui@Proside.pt');
    expect(session('oauth_expect'))->toBe('rui@proside.pt');

    hub_login('hub-1', 'ana@proside.pt')->assertRedirect('/login')->assertSessionHas('error');
    $this->assertGuest();
    expect(session('error'))->toContain('ana@proside.pt')->toContain('rui@proside.pt')
        ->and(DB::table('youtrack_connections')->count())->toBe(0);
});

it('signs in when the account matches the remembered one, and ignores a malformed hint', function () {
    $ana = User::factory()->create(['email' => 'ana@proside.pt']);

    $this->get('/auth/youtrack/redirect?account=not-an-email');
    expect(session('oauth_expect'))->toBeNull();

    $this->get('/auth/youtrack/redirect?account=ANA@proside.pt');
    hub_login('hub-1', 'ana@proside.pt')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($ana->fresh());
});

it('shows previously used accounts only where the browser keeps them, and never offers the dev user', function () {
    $this->get('/login')->assertOk()->assertSee('previous-list', false)->assertSee(__('ui.login.previous'))->assertSee(__('ui.login.other_account'));

    $user = User::factory()->create(['email' => 'ana@proside.pt']);
    $this->actingAs($user)->get('/')->assertOk()->assertSee('"rememberAccount":true', false);
    $dev = User::factory()->create(['email' => \App\Http\Controllers\AuthController::DEV_EMAIL]);
    $this->actingAs($dev)->get('/')->assertOk()->assertSee('"rememberAccount":false', false);
});
