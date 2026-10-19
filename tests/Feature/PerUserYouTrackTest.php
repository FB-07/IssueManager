<?php

use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'services.youtrack.url' => 'https://yt.test', 'services.youtrack.token' => 'shared-token', 'services.youtrack.dev_login' => false,
        'services.youtrack.hub_url' => 'https://hub.test/hub', 'services.youtrack.client_id' => 'cid', 'services.youtrack.client_secret' => 'secret',
    ]);
});

it('shows each user only the projects their own YouTrack token allows (caches are per user)', function () {
    Http::fake(['yt.test/api/admin/projects?*' => fn ($r) => Http::response(
        $r->hasHeader('Authorization', 'Bearer token-ana')
            ? [['id' => '0-1', 'shortName' => 'WEB', 'name' => 'Website', 'archived' => false]]
            : [['id' => '0-2', 'shortName' => 'HR', 'name' => 'People', 'archived' => false], ['id' => '0-3', 'shortName' => 'FIN', 'name' => 'Finance', 'archived' => false]]
    )]);

    $ana = yt_user('token-ana');
    $bruno = yt_user('token-bruno');

    $this->actingAs($ana)->getJson('/api/youtrack/projects')->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.key', 'WEB');
    $this->actingAs($bruno)->getJson('/api/youtrack/projects')->assertOk()->assertJsonCount(2, 'projects')->assertJsonPath('projects.0.key', 'HR');
    // Ana again, from the cache: still hers
    $this->actingAs($ana)->getJson('/api/youtrack/projects')->assertOk()->assertJsonPath('projects.0.key', 'WEB');

    Http::assertNotSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer shared-token'));
});

it('refreshes an expired Hub token before calling YouTrack and keeps the new one', function () {
    Http::fake([
        'hub.test/hub/api/rest/oauth2/token' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'rt-new', 'expires_in' => 3600]),
        'yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'WEB', 'name' => 'Website']]),
    ]);
    $user = yt_user('old');
    $user->youtrackConnection->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($user)->getJson('/api/youtrack/projects')->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth2/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-old');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'admin/projects') && $r->hasHeader('Authorization', 'Bearer fresh'));
    expect($user->fresh()->youtrackConnection->access_token)->toBe('fresh')->and($user->fresh()->youtrackConnection->refresh_token)->toBe('rt-new');
});

it('asks to log in again when the token cannot be refreshed', function () {
    Http::fake(['hub.test/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    $user = yt_user('old');
    $user->youtrackConnection->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($user)->getJson('/api/youtrack/projects')->assertStatus(401)->assertJsonPath('reauth', true);
});

it('never falls back to the shared company token for a user without a Hub login', function () {
    Http::fake(['yt.test/*' => Http::response([])]);

    $this->actingAs(User::factory()->create())->getJson('/api/youtrack/projects')->assertStatus(401)->assertJsonPath('reauth', true);
    Http::assertNothingSent();
});

it('lets the local dev-login user use the shared token (local environment only)', function () {
    Http::fake(['yt.test/api/admin/projects?*' => Http::response([['id' => '0-1', 'shortName' => 'WEB', 'name' => 'Website']])]);
    config(['services.youtrack.dev_login' => true]);
    $dev = User::factory()->create(['email' => AuthController::DEV_EMAIL]);
    $other = User::factory()->create();

    app()['env'] = 'production';
    $this->actingAs($dev)->getJson('/api/youtrack/projects')->assertStatus(401);

    app()['env'] = 'local';
    Cache::flush();
    $this->actingAs($other)->getJson('/api/youtrack/projects')->assertStatus(401); // any other account never gets the shared token
    $this->actingAs($dev)->getJson('/api/youtrack/projects')->assertOk();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'admin/projects') && $r->hasHeader('Authorization', 'Bearer shared-token'));
});
