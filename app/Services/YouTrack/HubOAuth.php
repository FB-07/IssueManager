<?php

namespace App\Services\YouTrack;

use App\Exceptions\TrackerException;
use Illuminate\Support\Facades\Http;

/** OAuth 2.0 authorization-code login against the YouTrack Hub (used only to identify the user). */
class HubOAuth
{
    public function configured(): bool
    {
        $c = config('services.youtrack');

        return filled($c['hub_url']) && filled($c['client_id']) && filled($c['client_secret']);
    }

    public function redirectUri(): string
    {
        return config('services.youtrack.redirect') ?: route('auth.youtrack.callback');
    }

    public function authorizeUrl(string $state): string
    {
        $c = config('services.youtrack');

        return $c['hub_url'].'/api/rest/oauth2/auth?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $c['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $c['scopes'],
            'state' => $state,
            'request_credentials' => 'default',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{id:string,name:string,email:?string,avatar:?string} */
    public function profileFromCode(string $code): array
    {
        $c = config('services.youtrack');

        $token = Http::withBasicAuth($c['client_id'], $c['client_secret'])->asForm()->acceptJson()->timeout(15)
            ->post($c['hub_url'].'/api/rest/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);
        if ($token->failed() || blank($token->json('access_token'))) {
            throw new TrackerException(__('ui.login.failed'), $token->status());
        }

        $me = Http::withToken($token->json('access_token'))->acceptJson()->timeout(15)
            ->get($c['hub_url'].'/api/rest/users/me', ['fields' => 'id,name,login,profile(email(email),avatar(url))']);
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
}
