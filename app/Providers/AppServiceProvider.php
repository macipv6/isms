<?php

namespace App\Providers;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\SocialiteMicrosoftIdentityProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EntraIdentityProvider::class, SocialiteMicrosoftIdentityProvider::class);
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });

        RateLimiter::for('entra-auth', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
        ]);
    }
}
