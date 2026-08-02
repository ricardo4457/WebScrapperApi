<?php

use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

// --- Test setup ----------------------------------------------------------

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.node_scraper.url', 'http://node-scraper.test');
    Config::set('services.node_scraper.callback_base_url', 'http://laravel.test');
});

/**
 * Returns a valid scraping payload used across the tests.
 */
if (! function_exists('scrapePayload')) {
    function scrapePayload(): array
    {
        return [
            'strategy' => 'single_school',
            'district' => 'Porto',
            'city'     => 'Ermesinde',
            'school'   => 'Escola Teste',
            'year'     => '5º Ano',
        ];
    }
}

// Ensure a successful Node response creates the run and its jobs.
it('cria o run, regista os jobs e marca o run como running quando o Node responde com sucesso', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response([
            'job_tokens' => ['job-a', 'job-b'],
            'jobs_total' => 2,
        ], 200),
    ]);

    $service = app(ScrapeDispatchService::class);

    $result = $service->dispatch(scrapePayload());

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe(202)
        ->and($result['jobs_total'])->toBe(2);

    $run = $result['run']->fresh();

    expect($run->status)->toBe('running')
        ->and($run->jobs_total)->toBe(2)
        ->and(ScrapeRunJob::where('scrape_run_id', $run->id)->count())->toBe(2);
});

// Ensure Node API errors mark the run as failed and return ok=false.
it('marca o run como failed e devolve ok=false quando o Node responde com erro', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['message' => 'strategy indisponível'], 500),
    ]);

    $service = app(ScrapeDispatchService::class);

    $result = $service->dispatch(scrapePayload());

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(500);

    $run = $result['run']->fresh();

    expect($run->status)->toBe('failed')
        ->and(ScrapeRunJob::where('scrape_run_id', $run->id)->count())->toBe(0);
});

// Ensure connection failures also mark the run as failed.
it('marca o run como failed quando não consegue ligar ao Node', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused.');
    });

    $service = app(ScrapeDispatchService::class);

    $result = $service->dispatch(scrapePayload());

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(500);

    $run = $result['run']->fresh();

    expect($run->status)->toBe('failed');
});

// Ensure the run record is created before contacting the Node service.
it('cria o run mesmo antes de saber se o Node vai responder com sucesso', function () {
    Http::fake([
        'node-scraper.test/*' => Http::response(['job_tokens' => ['job-a'], 'jobs_total' => 1], 200),
    ]);

    $countBefore = ScrapeRun::count();

    app(ScrapeDispatchService::class)->dispatch(scrapePayload());

    expect(ScrapeRun::count())->toBe($countBefore + 1);
});
