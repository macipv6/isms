<?php

namespace App\Providers;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\SocialiteMicrosoftIdentityProvider;
use App\Models\ProjectThreat;
use App\Models\ProtectionNeed;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Policies\ProjectThreatPolicy;
use App\Policies\ProtectionNeedPolicy;
use App\Policies\RiskAnalysisPolicy;
use App\Policies\RiskMethodologyVersionPolicy;
use App\Policies\ThreatCatalogVersionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(ProtectionNeed::class, ProtectionNeedPolicy::class);
        Gate::policy(ProjectThreat::class, ProjectThreatPolicy::class);
        Gate::policy(RiskAnalysisCycle::class, RiskAnalysisPolicy::class);
        Gate::policy(RiskMethodologyVersion::class, RiskMethodologyVersionPolicy::class);
        Gate::policy(ThreatCatalogVersion::class, ThreatCatalogVersionPolicy::class);
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });

        RateLimiter::for('entra-auth', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
        ]);
    }
}
