<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AppController extends Controller
{
    use ScopesToUser;

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $locales = config('issuemanager.locales');
        $loader = app('translator')->getLoader();

        return view('app', ['boot' => [
            'user' => ['name' => $user->name, 'email' => $user->email, 'avatar' => $this->avatar($user)],
            'rememberAccount' => $user->email !== AuthController::DEV_EMAIL, // shown on the login page next time (this browser only)
            'theme' => $user->theme === 'light' ? 'light' : 'dark',
            'locale' => in_array($user->locale, $locales, true) ? $user->locale : 'pt',
            'locales' => $locales,
            'i18n' => collect($locales)->mapWithKeys(fn ($l) => [$l => $loader->load($l, 'ui')]),
            'icons' => config('icons'),
            'trackerUrl' => config('services.youtrack.url'),
            'configured' => YouTrackClient::configured(),
            'page' => match ($request->route()?->getName()) {
                'mine' => 'mine',
                'issues' => 'all',
                'analytics' => 'analytics',
                default => 'chat',
            },
            'initialId' => $request->route('id') ? (int) $request->route('id') : null,
            'conversations' => $user->conversations()->latest('updated_at')->limit(200)->get()->map(Presenter::conversation(...))->all(),
        ]]);
    }

    /**
     * The picture of the signed-in user: the one the Hub gave at login (a Hub avatar goes through the app's image proxy, the
     * browser has no token), otherwise the one the YouTrack teams have for their name (looked up once a day).
     */
    private function avatar($user): ?string
    {
        if (! YouTrackClient::configured()) {
            return $user->avatar_url && str_starts_with($user->avatar_url, 'https://') ? $user->avatar_url : null;
        }

        $yt = app(YouTrackClient::class);
        $url = (string) $user->avatar_url;
        $path = parse_url($url, PHP_URL_PATH).(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
        if ($proxied = $yt->imageUrl($path ?: null)) {
            return $proxied;
        }
        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        return Cache::remember('avatar.'.$user->id, 86400, function () use ($yt, $user) {
            try {
                return array_values($yt->avatarsByName([$user->name]))[0] ?? null;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
