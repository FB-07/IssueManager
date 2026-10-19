<?php

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Models\Conversation;
use App\Models\User;
use App\Services\YouTrack\HubOAuth;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Login through the YouTrack Hub. Only invited accounts (php artisan issuemanager:invite) get in. */
class AuthController extends Controller
{
    /** The only account the local dev login signs in (and the only one allowed to use the shared YouTrack token). */
    public const DEV_EMAIL = 'dev@issuemanager.test';

    public function show(HubOAuth $hub)
    {
        return view('login', ['oauthConfigured' => $hub->configured(), 'devLogin' => $this->devLoginAllowed()]);
    }

    public function redirect(Request $request, HubOAuth $hub)
    {
        if (! $hub->configured()) {
            return redirect()->route('login')->with('error', __('ui.login.not_configured'));
        }
        $request->session()->put('oauth_state', $state = Str::random(40));

        return redirect()->away($hub->authorizeUrl($state));
    }

    public function callback(Request $request, HubOAuth $hub)
    {
        $expected = $request->session()->pull('oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('login')->with('error', __('ui.login.failed'));
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('login')->with('error', __('ui.login.cancelled'));
        }

        try {
            $token = $hub->exchangeCode((string) $request->query('code'));
            $profile = $hub->profile($token['access_token']);
        } catch (TrackerException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        $user = User::where('hub_id', $profile['id'])->first()
            ?? ($profile['email'] ? User::whereRaw('lower(email) = ?', [mb_strtolower($profile['email'])])->first() : null);

        if (! $user) {
            return redirect()->route('login')->with('error', __('ui.login.not_invited', ['email' => $profile['email'] ?? $profile['name']]));
        }

        $user->update(['hub_id' => $profile['id'], 'name' => $profile['name'], 'avatar_url' => $profile['avatar']]);
        $user->youtrackConnection()->updateOrCreate([], [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600)) - 30),
            'scopes' => $token['scope'] ?? null,
        ]);

        // the account must really be allowed to use YouTrack (the Hub scope for the YouTrack service has to be granted)
        try {
            (new YouTrackClient($user->fresh(), $hub))->me();
        } catch (TrackerException $e) {
            $user->youtrackConnection()->delete();

            return redirect()->route('login')->with('error', __('ui.login.no_youtrack'));
        }

        return $this->loginAs($request, $user);
    }

    /** Local development only, and only when AUTH_DEV_LOGIN=true. */
    public function dev(Request $request)
    {
        abort_unless($this->devLoginAllowed(), 404);

        return $this->loginAs($request, User::firstOrCreate(['email' => self::DEV_EMAIL], ['name' => 'Dev']));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function loginAs(Request $request, User $user)
    {
        Auth::login($user, true);
        $request->session()->regenerate();

        // history created before login lives under an anonymous cookie: it becomes this user's on first login
        $legacy = $request->cookie('im_owner');
        if (is_string($legacy) && Str::isUuid($legacy)) {
            Conversation::where('owner', $legacy)->whereNull('user_id')->update(['user_id' => $user->id]);
        }

        return redirect()->intended(route('home'));
    }

    private function devLoginAllowed(): bool
    {
        return app()->isLocal() && config('services.youtrack.dev_login');
    }
}
