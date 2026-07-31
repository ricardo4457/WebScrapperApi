<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the X-App-Key header sent by the Vue frontend.
 *
 * Protects endpoints that start or monitor real scraping operations.
 *
 * This middleware is independent from the node.apikey middleware used
 * for the Node -> Laravel callback and must not be applied to the
 * /book-scraper/callback route.
 */

class VerifyAppApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.frontend.app_key');

        // Falha de configuração no servidor nunca deve abrir a porta.
        if (empty($expected)) {
            report(new \RuntimeException('APP_API_KEY não configurada no ambiente.'));

            return response()->json(['error' => 'Serviço indisponível.'], 503);
        }

        $provided = $request->header('X-App-Key');

        if (empty($provided) || !hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Não autorizado.'], 401);
        }

        return $next($request);
    }
}
