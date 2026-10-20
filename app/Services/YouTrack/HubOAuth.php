<?php

namespace App\Services\YouTrack;

use App\Exceptions\TrackerException;
use App\Models\YouTrackConnection;
use Illuminate\Support\Facades\Http;

/** OAuth 2.0 authorization-code login against the YouTrack Hub. The user's token is then used for the YouTrack API. */
class HubOAuth
{
    public function configured(): bool
    {
        $c = config('services.youtrack');

        return filled($c['hub_url']) && filled($c['client_id']) && filled($c['client_secret']);
    }

    public function redirectUri(): string
    {
        // Pinned to APP_URL (not to the scheme/host of the current request) so it always equals the Redirect URI registered in the Hub
        return config('services.youtrack.redirect') ?: rtrim((string) config('app.url'), '/').route('auth.youtrack.callback', [], false);
    }

    /** `$forceLogin` always asks for credentials (to sign in with a different account) instead of reusing the Hub session. */
    public function authorizeUrl(string $state, bool $forceLogin = false): string
    {
        $c = config('services.youtrack');

        return $c['hub_url'].'/api/rest/oauth2/auth?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $c['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $c['scopes'],
            'state' => $state,
            'access_type' => 'offline', // also returns a refresh token
            'request_credentials' => $forceLogin ? 'required' : 'default',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token:string,refresh_token:?string,expires_in:int,scope:?string} */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri()]);
    }

    /** Gets a new access token with the stored refresh token. @throws TrackerException (status 401) when it cannot */
    public function refresh(YouTrackConnection $connection): YouTrackConnection
    {
        if (blank($connection->refresh_token)) {
            throw new TrackerException(__('ui.errors.session_expired'), 401);
        }

        try {
            $t = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token, 'scope' => config('services.youtrack.scopes')]);
        } catch (TrackerException) {
            throw new TrackerException(__('ui.errors.session_expired'), 401);
        }

        $connection->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds(max(60, (int) ($t['expires_in'] ?? 3600)) - 30),
        ]);

        return $connection;
    }

    /** @return array{id:string,name:string,email:?string,avatar:?string} */
    public function profile(string $accessToken): array
    {
        $me = Http::withToken($accessToken)->acceptJson()->timeout(15)
            ->get(config('services.youtrack.hub_url').'/api/rest/users/me', ['fields' => 'id,name,login,profile(email(email),avatar(url))']);
        if ($me->failed()) {
            throw new TrackerException(__('ui.login.failed'), $me->status());
        }
        $j = $me->json();

        return [
            'id' => $j['id'],
            'name' => $j['name'] ?? $j['login'] ?? 'YouTrack',
            'email' => $j['profile']['email']['email'] ?? null,
            'avatar' => $j['profile']['avatar']['url'] ?? null,
        ];
    }

    private function tokenRequest(array $form): array
    {
        $c = config('services.youtrack');
        $r = Http::withBasicAuth($c['client_id'], $c['client_secret'])->asForm()->acceptJson()->timeout(15)
            ->post($c['hub_url'].'/api/rest/oauth2/token', $form);

        if ($r->failed() || blank($r->json('access_token'))) {
            throw new TrackerException(__('ui.login.failed'), $r->status());
        }

        return $r->json();
    }
}
