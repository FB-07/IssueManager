<?php

namespace App\Providers;

use App\Services\YouTrack\HubOAuth;
use App\Services\YouTrack\YouTrackClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // YouTrack is always called as the logged-in user (their own Hub token), resolved at call time
        $this->app->bind(YouTrackClient::class, fn ($app) => new YouTrackClient(null, $app->make(HubOAuth::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.force_https')) {
            URL::forceHttps();
        }
    }
}
