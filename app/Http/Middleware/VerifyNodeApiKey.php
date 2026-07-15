<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VerifyNodeApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.node_scraper.api_key');

        if (!is_string($expectedKey) || $expectedKey === '') {
            throw new HttpException(500, 'Scraper API key is not configured on the server.');
        }

        $providedKey = $request->header('X-API-KEY');


        if (!is_string($providedKey) || !hash_equals($expectedKey, $providedKey)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
