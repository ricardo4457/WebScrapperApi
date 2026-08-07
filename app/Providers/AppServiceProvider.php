<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('scrape-run', function ($request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Heavy scrape endpoints (district/city/teaching-cycle).
        RateLimiter::for('scrape-run-heavy', function ($request) {
            return Limit::perMinute(1)->by($request->ip());
        });

        // Lookup endpoints that may trigger discover=1 scrapes during normal
        // wizard navigation, so they use a looser limit.
        RateLimiter::for('scrape-lookup', function ($request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
