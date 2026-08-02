<?php

use App\Http\Middleware\VerifyRequestOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

// --- Test helper ---------------------------------------------------------

uses(Tests\TestCase::class);

if (! function_exists('runVerifyRequestOrigin')) {
    function runVerifyRequestOrigin(Request $request)
    {
        $middleware = new VerifyRequestOrigin();

        return $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
    }
}

// Returns 403 when neither Origin nor Referer is provided.
it('devolve 403 quando não é enviado Origin nem Referer', function () {
    Config::set('services.frontend.allowed_origins', ['http://localhost:5173']);

    $request = Request::create('/api/books/search', 'GET');

    $response = runVerifyRequestOrigin($request);

    expect($response->getStatusCode())->toBe(403);
});

// Returns 403 when the Origin is not in the allow list.
it('devolve 403 quando o Origin não está na lista de origens permitidas', function () {
    Config::set('services.frontend.allowed_origins', ['http://localhost:5173']);

    $request = Request::create('/api/books/search', 'GET');
    $request->headers->set('Origin', 'https://site-malicioso.com');

    $response = runVerifyRequestOrigin($request);

    expect($response->getStatusCode())->toBe(403);
});

// Allows the request when the Origin matches an allowed origin.
it('deixa passar quando o Origin corresponde a uma origem permitida', function () {
    Config::set('services.frontend.allowed_origins', ['http://localhost:5173']);

    $request = Request::create('/api/books/search', 'GET');
    $request->headers->set('Origin', 'http://localhost:5173');

    $response = runVerifyRequestOrigin($request);

    expect($response->getStatusCode())->toBe(200);
});

// Accepts Referer as a fallback when Origin is missing.
it('aceita o Referer como alternativa quando não há Origin', function () {
    Config::set('services.frontend.allowed_origins', ['http://localhost:5173']);

    $request = Request::create('/api/books/search', 'GET');
    $request->headers->set('Referer', 'http://localhost:5173/pesquisa');

    $response = runVerifyRequestOrigin($request);

    expect($response->getStatusCode())->toBe(200);
});
