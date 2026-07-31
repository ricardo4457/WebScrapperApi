<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Rate limiters for frontend scraping endpoints.
        // Use both minute and daily limits to reduce bursts and repeated requests..

        RateLimiter::for('scrape-run', function (Request $request) {
            return [
                Limit::perMinute(3)->by($request->ip()),
                Limit::perDay(50)->by($request->ip()),
            ];
        });

        RateLimiter::for('scrape-run-heavy', function (Request $request) {
            // Stricter limits for heavier district/city discovery runs.
            return [
                Limit::perMinute(2)->by($request->ip()),
                Limit::perDay(20)->by($request->ip()),
            ];
        });
    }
}
