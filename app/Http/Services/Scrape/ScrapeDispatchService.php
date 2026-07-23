<?php

namespace App\Http\Services\Scrape;

use App\Models\ScrapeRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extracted from ScrapeController::dispatchRun() so the same "create run,
 * call Node, register jobs, start run" flow can be reused by BookSearchService
 * (automatic fallback when a search misses the DB) without duplicating it.
 */
class ScrapeDispatchService
{
    public function __construct(
        private ScrapeRunService $runs,
        private ScrapeJobService $jobs,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   status: int,
     *   run: ScrapeRun,
     *   jobs_total: int,
     *   error: ?string,
     *   body: ?array
     * }
     */
    public function dispatch(array $validated): array
    {
        $run = $this->runs->create($validated);
        $jobTokens = [Str::uuid()->toString(), Str::uuid()->toString()];

        try {
            $callbackUrl = rtrim(config('services.node_scraper.callback_base_url'), '/')
                . route('book-scraper.callback', absolute: false);

            $response = Http::post(config('services.node_scraper.url') . '/scrape', [
                ...$validated,
                'callback_url' => $callbackUrl,
                'run_token'    => $run->token,
                'job_tokens'   => $jobTokens,
            ]);

            Log::debug('[ScrapeDispatchService] Payload enviado ao Node.', [
                'url'       => config('services.node_scraper.url') . '/scrape',
                'run_token' => $run->token,
            ]);

            if ($response->failed()) {
                Log::error('[ScrapeDispatchService] Node scraper returned an error status code.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                $this->runs->fail($run, $response->body());

                return [
                    'ok'         => false,
                    'status'     => $response->status(),
                    'run'        => $run,
                    'jobs_total' => 0,
                    'error'      => 'Failed to start scrape.',
                    'body'       => $response->json(),
                ];
            }

            $body = $response->json();
            $jobTokens = $body['job_tokens'] ?? $jobTokens;

            $this->jobs->createJobs($run, $jobTokens);

            $jobsTotal = $body['jobs_total'] ?? count($jobTokens);
            $this->runs->start($run, $jobsTotal);

            return [
                'ok'         => true,
                'status'     => 202,
                'run'        => $run,
                'jobs_total' => $jobsTotal,
                'error'      => null,
                'body'       => $body,
            ];
        } catch (Throwable $e) {
            Log::error('[ScrapeDispatchService] Unable to establish connection with Node scraper.', [
                'error' => $e->getMessage(),
            ]);

            $this->runs->fail($run, $e->getMessage());

            return [
                'ok'         => false,
                'status'     => 500,
                'run'        => $run,
                'jobs_total' => 0,
                'error'      => 'Internal server error.',
                'body'       => null,
            ];
        }
    }
}
