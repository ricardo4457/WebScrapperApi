<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the request comes from an allowed Origin or Referer.
 *
 * This is an additional protection layer and does not replace API-key
 * authentication.
 */
class VerifyRequestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('services.frontend.allowed_origins', []);

        $origin = $request->header('Origin') ?? $request->header('Referer');

        if (empty($origin)) {
            return response()->json(['error' => 'Não autorizado.'], 403);
        }

        $matches = collect($allowed)->contains(
            fn (string $allowedOrigin) => $allowedOrigin !== '' && str_starts_with($origin, $allowedOrigin)
        );

        if (!$matches) {
            return response()->json(['error' => 'Não autorizado.'], 403);
        }

        return $next($request);
    }
}
