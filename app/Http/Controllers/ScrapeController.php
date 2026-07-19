<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeCallbackRequest;
use App\Http\Requests\StartScrapeRequest;
use App\Http\Services\Scrape\ScrapeCallbackService;
use App\Http\Services\Scrape\ScrapeJobService;
use App\Http\Services\Scrape\ScrapeRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScrapeController extends Controller
{
    public function __construct(
        private ScrapeRunService $runs,
        private ScrapeJobService $jobs,
        private ScrapeCallbackService $callbacks,
    ) {}

    /**
     * Trigger a new scrape run and dispatch it to the Node service.
     */
    public function runScrape(StartScrapeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // 1. Generate the Run record + dynamic 48-char token
        $run = $this->runs->create($validated);
        $jobTokens = [Str::uuid()->toString(), Str::uuid()->toString()];
        try {
            // 2. Dispatch request to Node.js Scraper on correct '/scrape' endpoint
            $callbackUrl = rtrim(config('services.node_scraper.callback_base_url'), '/')
                . route('book-scraper.callback', absolute: false);

            $response = Http::post(config('services.node_scraper.url') . '/scrape', [
                ...$validated,
                'callback_url' => $callbackUrl,
                'run_token'    => $run->token,
                'job_tokens'   => $jobTokens,
            ]);

            // DEBUG: Log request sent to Node and raw response

            Log::debug('[ScrapeController] Payload enviado ao Node.', [
                'url'     => config('services.node_scraper.url') . '/scrape',
                'payload' => [
                    ...$validated,
                    'callback_url' => $callbackUrl,
                    'run_token'    => $run->token,
                    'job_tokens'   => $jobTokens,
                ],
            ]);
            Log::debug('[ScrapeController] Resposta bruta do Node.', [
                'status' => $response->status(),
                'body'   => $response->body(), // Raw body in case JSON parsing fails
            ]);

            if ($response->failed()) {
                Log::error('Node scraper returned an error status code.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                $this->runs->fail($run, $response->body());

                return response()->json([
                    'message' => 'Failed to start scrape.',
                    'details' => $response->json(),
                ], $response->status());
            }

            $body = $response->json();
            $jobTokens = $body['job_tokens'] ?? [];

            // 3. Register jobs locally and spin up run session
            $this->jobs->createJobs($run, $jobTokens);

            $jobsTotal = $body['jobs_total'] ?? count($jobTokens);
            $this->runs->start($run, $jobsTotal);

            return response()->json([
                'message'    => 'Scrape started successfully.',
                'run_id'     => $run->id,
                'jobs_total' => $jobsTotal,
            ], 202);
        } catch (Throwable $e) {
            Log::error('Unable to establish connection with Node scraper.', [
                'error' => $e->getMessage(),
            ]);

            $this->runs->fail($run, $e->getMessage());

            return response()->json([
                'message' => 'Internal server error.',
            ], 500);
        }
    }

    /**
     * Handle incoming callback from the Node scraper.
     */
    public function callback(ScrapeCallbackRequest $request): JsonResponse
    {
        try {
            Log::info('[ScrapeCallback] Processing incoming callback.', [
                'run_token' => $request->input('run_token'),
                'job_token' => $request->input('job_token'),
                'status'    => $request->input('status'),
                'books_count' => is_array($request->input('books')) ? count($request->input('books')) : 0,
            ]);

            // DEBUG: Log full callback payload
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

        return response()->json([
            'status'          => $run->status,
            'progress'        => "{$run->jobs_done} of {$run->jobs_total} completed",
            'failed'          => $run->jobs_failed,
            'last_updated'    => $run->updated_at,
            'books_imported'  => $run->jobs->sum('books_imported'),
            'books_skipped'   => $run->jobs->sum('books_skipped'),
            'errors_per_job'  => $run->jobs
                ->filter(fn($job) => !empty($job->import_errors))
                ->map(fn($job) => [
                    'job_token' => $job->job_token,
                    'errors'    => $job->import_errors,
                ])
                ->values(),
        ]);
    }
}
