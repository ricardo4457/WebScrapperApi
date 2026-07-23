<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\ScrapeRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyNodeApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Get the dynamic token sent in the JSON body of the callback
        $providedToken = $request->input('run_token');

        if (!is_string($providedToken) || $providedToken === '') {
            return response()->json(['message' => 'Unauthorized: Missing run_token'], 401);
        }

        $runExists = ScrapeRun::where('token', $providedToken)->exists();

        // 3. If no run matches at all, the token is forged/unknown — block it.
        if (!$runExists) {
            return response()->json(['message' => 'Unauthorized: Invalid or expired token'], 401);
        }

        return $next($request);
    }
}
