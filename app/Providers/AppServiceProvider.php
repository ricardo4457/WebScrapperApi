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

        RateLimiter::for('scrape-run-heavy', function ($request) {
            return Limit::perMinute(1)->by($request->ip());
        });
    }
}
