<?php

use App\Http\Middleware\VerifyAppApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

// --- Test helper ---------------------------------------------------------


uses(Tests\TestCase::class);

/**
 * Executes the middleware and returns its response.
 */
if (! function_exists('runVerifyAppApiKey')) {
    function runVerifyAppApiKey(Request $request)
    {
        $middleware = new VerifyAppApiKey();

        return $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
    }
}

// Returns 503 when the API key is not configured.
it('devolve 503 quando a APP_API_KEY não está configurada no servidor', function () {
    Config::set('services.frontend.app_key', null);

    $request = Request::create('/api/books/search', 'GET');

    $response = runVerifyAppApiKey($request);

    expect($response->getStatusCode())->toBe(503);
});

// Returns 401 when the X-App-Key header is missing.
it('devolve 401 quando o header X-App-Key não é enviado', function () {
    Config::set('services.frontend.app_key', 'chave-secreta');

    $request = Request::create('/api/books/search', 'GET');

    $response = runVerifyAppApiKey($request);

    expect($response->getStatusCode())->toBe(401);
});

// Returns 401 when the provided API key is invalid.
it('devolve 401 quando o header X-App-Key não corresponde à chave configurada', function () {
    Config::set('services.frontend.app_key', 'chave-secreta');

    $request = Request::create('/api/books/search', 'GET');
    $request->headers->set('X-App-Key', 'chave-errada');

    $response = runVerifyAppApiKey($request);

    expect($response->getStatusCode())->toBe(401);
});

// Allows the request when the API key is valid.
it('deixa passar o pedido quando o header X-App-Key corresponde à chave configurada', function () {
    Config::set('services.frontend.app_key', 'chave-secreta');

    $request = Request::create('/api/books/search', 'GET');
    $request->headers->set('X-App-Key', 'chave-secreta');

    $response = runVerifyAppApiKey($request);

    expect($response->getStatusCode())->toBe(200);
});
