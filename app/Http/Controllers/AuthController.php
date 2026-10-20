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

/** Login through the YouTrack Hub. Only accounts of the company email domain (config issuemanager.allowed_domain) get in. */
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

        // `account` = a previously used account chosen on the login page: the Hub reuses its own session, so the result is
        // checked against it in the callback; `other` = ask for credentials again, to sign in with a different account
        $account = $request->query('account');
        if (is_string($account) && filter_var($account, FILTER_VALIDATE_EMAIL)) {
            $request->session()->put('oauth_expect', mb_strtolower($account));
        } else {
            $request->session()->forget('oauth_expect');
        }

        return redirect()->away($hub->authorizeUrl($state, $request->boolean('other')));
    }

    public function callback(Request $request, HubOAuth $hub)
    {
        $expected = $request->session()->pull('oauth_state');
        $expectedAccount = $request->session()->pull('oauth_expect');
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

        if ($expectedAccount && mb_strtolower((string) $profile['email']) !== $expectedAccount) {
            return redirect()->route('login')->with('error', __('ui.login.wrong_account', ['got' => $profile['email'] ?? $profile['name'], 'expected' => $expectedAccount]));
        }

        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));
        if (! $this->allowedEmail($email)) {
            return redirect()->route('login')->with('error', __('ui.login.domain_only', ['domain' => config('issuemanager.allowed_domain'), 'email' => $email !== '' ? $email : $profile['name']]));
        }

        // no invitations: the first login of an account of the domain creates its user
        $user = User::where('hub_id', $profile['id'])->first()
            ?? User::whereRaw('lower(email) = ?', [$email])->first()
            ?? User::create(['email' => $email, 'name' => $profile['name']]);

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

    /** The email must be exactly `name@<allowed domain>`: "x@proside.pt.evil.com" or "x@evilproside.pt" do not pass. */
    private function allowedEmail(string $email): bool
    {
        $domain = (string) config('issuemanager.allowed_domain');
        $at = strrpos($email, '@');

        return $domain !== '' && $at !== false && $at > 0 && substr($email, $at + 1) === $domain && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function devLoginAllowed(): bool
    {
        return app()->isLocal() && config('services.youtrack.dev_login');
    }
}
