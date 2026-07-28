<?php

use App\Http\Services\Scrape\ScrapeJobService;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

if (! function_exists('makeScrapeRunJob')) {
    function makeScrapeRunJob(array $attrs = []): ScrapeRunJob
    {
        $run = ScrapeRun::create([
            'token'  => Str::random(48),
            'status' => 'pending',
            'params' => [],
        ]);

        return $run->jobs()->create(array_merge([
            'job_token' => Str::random(32),
            'status'    => 'running',
        ], $attrs));
    }
}

it('accumulateProgress ignora callback de um attempt mais antigo que last_attempt_seen', function () {
    $service = new ScrapeJobService();

    $job = makeScrapeRunJob([
        'last_attempt_seen' => 3,
        'books_imported'    => 5,
        'books_skipped'     => 1,
    ]);

    $service->accumulateProgress($job, ['imported' => 10, 'skipped' => 10, 'errors' => []], 2);

    $job->refresh();

    expect($job->books_imported)->toBe(5)
        ->and($job->books_skipped)->toBe(1)
        ->and($job->last_attempt_seen)->toBe(3);
});

it('complete/fail são idempotentes: chamar duas vezes não duplica efeito', function () {
    $service = new ScrapeJobService();
    $job = makeScrapeRunJob(['last_attempt_seen' => 1]);

    $result1 = $service->complete($job, [], 2);
    $job->refresh();
    $reportedAtFirst = $job->reported_at;

    // Attempt mais antigo que o já registado, deve ser ignorado
    $result2 = $service->complete($job, [], 1);
    $job->refresh();

    expect($result1)->toBeTrue()
        ->and($job->status)->toBe('completed')
        ->and($result2)->toBeFalse()
        ->and($job->reported_at->equalTo($reportedAtFirst))->toBeTrue();
});

it('fail é idempotente: chamar duas vezes não duplica efeito', function () {
    $service = new ScrapeJobService();
    $job = makeScrapeRunJob(['last_attempt_seen' => 1]);

    $result1 = $service->fail($job, 'erro qualquer', [], 2);
    $job->refresh();

    // Attempt mais antigo, deve ser ignorado e não sobrescrever o erro
    $result2 = $service->fail($job, 'outro erro', [], 1);
    $job->refresh();

    expect($result1)->toBeTrue()
        ->and($job->status)->toBe('failed')
        ->and($job->error_message)->toBe('erro qualquer')
        ->and($result2)->toBeFalse();
});

it('isFinished devolve true só para completed/failed', function () {
    $service = new ScrapeJobService();

    $pending = makeScrapeRunJob(['status' => 'pending']);
    $running = makeScrapeRunJob(['status' => 'running']);
    $completed = makeScrapeRunJob(['status' => 'completed']);
    $failed = makeScrapeRunJob(['status' => 'failed']);

    expect($service->isFinished($pending))->toBeFalse()
        ->and($service->isFinished($running))->toBeFalse()
        ->and($service->isFinished($completed))->toBeTrue()
        ->and($service->isFinished($failed))->toBeTrue();
});
