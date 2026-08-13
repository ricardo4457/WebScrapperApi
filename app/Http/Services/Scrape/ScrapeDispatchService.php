<?php

namespace App\Http\Services\Scrape;

use App\Models\ScrapeRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates and starts a scrape run through the Node scraper.
 */
class ScrapeDispatchService
{
    public function __construct(
        private ScrapeRunService $runs,
        private ScrapeJobService $jobs,
    ) {}

    public function dispatch(array $validated): array
    {
        $existing = $this->findRunningDuplicate($validated);

        if ($existing) {
            return [
                'ok'         => true,
                'status'     => 202,
                'run'        => $existing,
                'jobs_total' => $existing->jobs_total,
                'error'      => null,
                'body'       => null,
            ];
        }

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


    /**
     * Defines the fields used to identify duplicate scrape requests.
     */
    private const SCOPE_KEYS = ['strategy', 'district', 'city', 'school', 'year', 'teaching_cycle', 'course'];

    /**
     * Finds an existing pending or running scrape with the same scope.
     */
    private function findRunningDuplicate(array $validated): ?ScrapeRun
    {
        $scope = $this->normalizeScope($validated);

        $query = ScrapeRun::query()->whereIn('status', ['pending', 'running']);

        // Narrow the candidates before performing the full scope comparison.
        if (!empty($scope['strategy'])) {
            $query->whereJsonContains('params->strategy', $scope['strategy']);
        }

        return $query->latest('id')
            ->get()
            ->first(fn(ScrapeRun $run) => $this->normalizeScope($run->params ?? []) === $scope);
    }

    /**
     * Normalizes the scope so missing and null values are treated equally.
     */
    private function normalizeScope(array $params): array
    {
        $scope = [];

        foreach (self::SCOPE_KEYS as $key) {
            $scope[$key] = $params[$key] ?? null;
        }

        return $scope;
    }
}
