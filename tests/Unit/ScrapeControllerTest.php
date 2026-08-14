<?php
use Tests\TestCase;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);
// --- Test setup ----------------------------------------------------------

beforeEach(function () {
    Config::set('services.frontend.app_key', 'chave-secreta');
    Config::set('services.frontend.allowed_origins', ['http://localhost:5173']);
    Config::set('services.node_scraper.url', 'http://node-scraper.test');
    Config::set('services.node_scraper.callback_base_url', 'http://laravel.test');
});

if (! function_exists('scrapeHeaders')) {
    function scrapeHeaders(): array
    {
        return [
            'X-App-Key' => 'chave-secreta',
            'Origin'    => 'http://localhost:5173',
        ];
    }
}

// Shared helpers. Guarded to prevent redeclaration when running the full test suite
// or this test file independently.
if (! function_exists('createActiveRun')) {
    function createActiveRun(array $overrides = []): ScrapeRun
    {
        return ScrapeRun::create(array_merge([
            'token'       => 'run-token-teste',
            'status'      => 'running',
            'jobs_total'  => 1,
            'jobs_done'   => 0,
            'jobs_failed' => 0,
            'params'      => [],
        ], $overrides));
    }
}

if (! function_exists('createJob')) {
    function createJob(ScrapeRun $run, array $overrides = []): ScrapeRunJob
    {
        return $run->jobs()->create(array_merge([
            'job_token'         => 'job-token-teste',
            'status'            => 'pending',
            'last_attempt_seen' => 0,
        ], $overrides));
    }
}

// --- Protected endpoints -------------------------------------------------

test('book-scraper/run sem X-App-Key devolve 401', function () {
    $response = $this->postJson('/api/book-scraper/run', [], [
        'Origin' => 'http://localhost:5173',
    ]);

    $response->assertStatus(401);
});

// --- POST /book-scraper/run ---------------------------------------------
// Ensure a valid request starts a scraping run.
// Ensure Node API errors are forwarded to the client.

test('book-scraper/run com strategy inválida devolve 422', function () {
    $response = $this->postJson('/api/book-scraper/run', [
        'strategy' => 'estrategia_inexistente',
        'district' => 'Porto',
        'city'     => 'Ermesinde',
        'school'   => 'Escola Teste',
        'year'     => '9º Ano',
    ], scrapeHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('strategy');
});

test('book-scraper/run com dados válidos inicia o scrape e devolve 202', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['job_tokens' => ['a', 'b'], 'jobs_total' => 2], 200),
    ]);

    $response = $this->postJson('/api/book-scraper/run', [
        'strategy' => 'single_school',
        'district' => 'Porto',
        'city'     => 'Ermesinde',
        'school'   => 'Escola Teste',
        'year'     => '9º Ano',
    ], scrapeHeaders());

    $response->assertStatus(202);
    $response->assertJsonStructure(['message', 'run_id', 'jobs_total']);
});

test('book-scraper/run devolve o erro e o estado devolvidos pelo Node quando este falha', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['message' => 'strategy indisponível'], 500),
    ]);

    $response = $this->postJson('/api/book-scraper/run', [
        'strategy' => 'single_school',
        'district' => 'Porto',
        'city'     => 'Ermesinde',
        'school'   => 'Escola Teste',
        'year'     => '9º Ano',
    ], scrapeHeaders());

    $response->assertStatus(500);
    $response->assertJsonStructure(['message', 'details']);
});

// --- POST /book-scraper/run/district ------------------------------------
// Ensure the request is dispatched with the full_district strategy.

test('book-scraper/run/district sem teaching_cycle devolve 422', function () {
    $response = $this->postJson('/api/book-scraper/run/district', [
        'district' => 'Porto',
        'year'     => '9º Ano',
    ], scrapeHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('teaching_cycle');
});

test('book-scraper/run/district com dados válidos inicia o scrape com strategy full_district', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['job_tokens' => ['a'], 'jobs_total' => 1], 200),
    ]);

    $response = $this->postJson('/api/book-scraper/run/district', [
        'district'       => 'Porto',
        'year'           => '9º Ano',
        'teaching_cycle' => '3º Ciclo',
    ], scrapeHeaders());

    $response->assertStatus(202);

    Http::assertSent(function ($request) {
        return $request['strategy'] === 'full_district';
    });
});

// --- POST /book-scraper/run/city ----------------------------------------
// Ensure the request is dispatched with the full_city strategy.

test('book-scraper/run/city sem city devolve 422', function () {
    $response = $this->postJson('/api/book-scraper/run/city', [
        'district'       => 'Porto',
        'year'           => '9º Ano',
        'teaching_cycle' => '3º Ciclo',
    ], scrapeHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('city');
});

test('book-scraper/run/city com dados válidos inicia o scrape com strategy full_city', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['job_tokens' => ['a'], 'jobs_total' => 1], 200),
    ]);

    $response = $this->postJson('/api/book-scraper/run/city', [
        'district'       => 'Porto',
        'city'           => 'Ermesinde',
        'year'           => '9º Ano',
        'teaching_cycle' => '3º Ciclo',
    ], scrapeHeaders());

    $response->assertStatus(202);

    Http::assertSent(function ($request) {
        return $request['strategy'] === 'full_city';
    });
});

// --- GET /book-scraper/status/{runId} -----------------------------------
// Ensure aggregated run and job progress is returned.
// Ensure completed runs with no imported books are flagged as no_results.

test('monitor devolve o progresso agregado do run e dos seus jobs', function () {
    $run = createActiveRun(['jobs_total' => 2, 'jobs_done' => 1]);

    createJob($run, [
        'status'          => 'completed',
        'books_imported'  => 5,
        'books_skipped'   => 1,
    ]);

    createJob($run, [
        'job_token' => 'job-a-decorrer',
        'status'    => 'running',
    ]);

    Http::fake([
        'node-scraper.test/*' => Http::response(['state' => 'active', 'progress' => 40], 200),
    ]);

    $response = $this->getJson("/api/book-scraper/status/{$run->id}", scrapeHeaders());

    $response->assertStatus(200);
    $response->assertJsonPath('status', $run->status);
    $response->assertJsonPath('books_imported', 5);
    $response->assertJsonPath('books_skipped', 1);
    $response->assertJsonCount(1, 'live_progress');
});

test('monitor assinala no_results quando o run terminou sem livros importados', function () {
    $run = createActiveRun([
        'status'     => 'completed',
        'jobs_total' => 1,
        'jobs_done'  => 1,
    ]);

    createJob($run, [
        'status'         => 'completed',
        'books_imported' => 0,
    ]);

    Http::fake();

    $response = $this->getJson("/api/book-scraper/status/{$run->id}", scrapeHeaders());

    $response->assertStatus(200);
    $response->assertJsonPath('no_results', true);
    $response->assertJsonPath('books_imported', 0);
});

test('monitor devolve 404 para um run inexistente', function () {
    $response = $this->getJson('/api/book-scraper/status/999999', scrapeHeaders());

    $response->assertStatus(404);
});
