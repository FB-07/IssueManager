<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToUser;
use App\Services\YouTrack\YouTrackClient;
use App\Support\Presenter;
use Illuminate\Http\Request;

class AppController extends Controller
{
    use ScopesToUser;

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $locales = config('issuemanager.locales');
        $loader = app('translator')->getLoader();

        return view('app', ['boot' => [
            'user' => ['name' => $user->name, 'email' => $user->email, 'avatar' => $user->avatar_url],
            'rememberAccount' => $user->email !== AuthController::DEV_EMAIL, // shown on the login page next time (this browser only)
            'theme' => $user->theme === 'light' ? 'light' : 'dark',
            'locale' => in_array($user->locale, $locales, true) ? $user->locale : 'pt',
            'locales' => $locales,
            'i18n' => collect($locales)->mapWithKeys(fn ($l) => [$l => $loader->load($l, 'ui')]),
            'icons' => config('icons'),
            'demo' => false,
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
}
