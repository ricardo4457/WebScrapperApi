<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyNodeApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedKey = $request->header('X-API-KEY');
        $expectedKey = config('services.node_scraper.api_key');

        if (!hash_equals($expectedKey, $providedKey)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
