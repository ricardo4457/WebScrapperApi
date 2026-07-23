<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeCallbackRequest;
use App\Http\Requests\StartDistrictScrapeRequest;
use App\Http\Requests\StartScrapeRequest;
use App\Http\Services\Scrape\ScrapeCallbackService;
use App\Http\Services\Scrape\ScrapeDispatchService;
use App\Http\Services\Scrape\ScrapeJobService;
use App\Http\Services\Scrape\ScrapeRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScrapeController extends Controller
{
    public function __construct(
        private ScrapeRunService $runs,
        private ScrapeJobService $jobs,
        private ScrapeCallbackService $callbacks,
        private ScrapeDispatchService $dispatcher,
    ) {}

    /**
     * Trigger a new scrape run (single_school / single_school_tooltip).
     */
    public function runScrape(StartScrapeRequest $request): JsonResponse
    {
        return $this->dispatchRun($request->validated());
    }

    /**
     * Trigger a full-district scrape run.
     */
    public function runDistrictScrape(StartDistrictScrapeRequest $request): JsonResponse
    {
        return $this->dispatchRun([
            ...$request->validated(),
            'strategy' => 'full_district',
        ]);
    }

    /**
     * Thin wrapper around ScrapeDispatchService::dispatch() that turns its
     * result array into the JsonResponse shape both endpoints above expect.
     * The actual "create run, call Node, register jobs" logic now lives in
     * ScrapeDispatchService so BookSearchService can reuse it too.
     */
    private function dispatchRun(array $validated): JsonResponse
    {
        $result = $this->dispatcher->dispatch($validated);

        if (!$result['ok']) {
            return response()->json([
                'message' => $result['error'],
                'details' => $result['body'],
            ], $result['status']);
        }

        return response()->json([
            'message'    => 'Scrape started successfully.',
            'run_id'     => $result['run']->id,
            'jobs_total' => $result['jobs_total'],
        ], 202);
    }

    /**
     * Handle incoming callback from the Node scraper.
     */
    public function callback(ScrapeCallbackRequest $request): JsonResponse
    {
        try {
            Log::info('[ScrapeCallback] Processing incoming callback.', [
                'run_token'   => $request->input('run_token'),
                'job_token'   => $request->input('job_token'),
                'status'      => $request->input('status'),
                'books_count' => is_array($request->input('books')) ? count($request->input('books')) : 0,
            ]);

            Log::debug('[ScrapeCallback] Payload bruto recebido.', [
                'all' => $request->all(),
            ]);

            $this->callbacks->process($request->validated());

            return response()->json([
                'message' => 'Callback processed successfully.',
            ]);
        } catch (Throwable $e) {
            Log::error('Callback processing failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to process callback.',
            ], 500);
        }
    }

    public function monitor(int $runId)
    {
        $run = \App\Models\ScrapeRun::with('jobs')->findOrFail($runId);

        $liveProgress = $run->jobs
            ->reject(fn($job) => in_array($job->status, ['completed', 'failed']))
            ->map(fn($job) => $this->fetchNodeJobProgress($job->job_token))
            ->filter()
            ->values();

        return response()->json([
            'status'         => $run->status,
            'progress'       => "{$run->jobs_done} of {$run->jobs_total} completed",
            'live_progress'  => $liveProgress,
            'failed'         => $run->jobs_failed,
            'last_updated'   => $run->updated_at,
            'books_imported' => $run->jobs->sum('books_imported'),
            'books_skipped'  => $run->jobs->sum('books_skipped'),
            'errors_per_job' => $run->jobs
                ->filter(fn($job) => !empty($job->import_errors))
                ->map(fn($job) => [
                    'job_token' => $job->job_token,
                    'errors'    => $job->import_errors,
                ])
                ->values(),
        ]);
    }

    private function fetchNodeJobProgress(string $jobToken): ?array
    {
        try {
            $response = Http::timeout(5)->get(
                rtrim(config('services.node_scraper.url'), '/') . "/scrape/{$jobToken}"
            );

            if ($response->failed()) {
                return null;
            }

            $body = $response->json();

            return [
                'job_token' => $jobToken,
                'state'     => $body['state'] ?? null,
                'percent'   => $body['progress'] ?? null,
            ];
        } catch (Throwable $e) {
            Log::warning('[ScrapeController] Unable to fetch live progress from Node.', [
                'job_token' => $jobToken,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }
}
