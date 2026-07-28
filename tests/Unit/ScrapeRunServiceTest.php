<?php

use App\Http\Services\Scrape\ScrapeRunService;
use App\Models\ScrapeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('finishIfComplete só marca completed/failed quando jobs_done + jobs_failed >= jobs_total', function () {
    $service = new ScrapeRunService();

    $run = ScrapeRun::create([
        'token'       => Str::random(48),
        'status'      => 'running',
        'params'      => [],
        'jobs_total'  => 3,
        'jobs_done'   => 1,
        'jobs_failed' => 0,
    ]);

    // Ainda não chegou ao total, não deve fechar o run
    $service->finishIfComplete($run);
    $run->refresh();
    expect($run->status)->toBe('running');

    $run->update(['jobs_done' => 2, 'jobs_failed' => 1]); // 2 + 1 = 3 = total

    $service->finishIfComplete($run);
    $run->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->completed_at)->not->toBeNull();
});

it('marca o run como failed quando nenhum job terminou com sucesso', function () {
    $service = new ScrapeRunService();

    $run = ScrapeRun::create([
        'token'       => Str::random(48),
        'status'      => 'running',
        'params'      => [],
        'jobs_total'  => 2,
        'jobs_done'   => 0,
        'jobs_failed' => 2,
    ]);

    $service->finishIfComplete($run);
    $run->refresh();

    expect($run->status)->toBe('failed');
});
