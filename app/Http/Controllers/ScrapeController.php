<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeCallbackRequest;
use App\Http\Requests\StartScrapeRequest;
use App\Http\Services\ScrapeCallbackService;
use App\Http\Services\ScrapeJobService;
use App\Http\Services\ScrapeRunService;
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
    ) {}

    /**
     * Trigger a new scrape run and dispatch it to the Node service.
     */
    public function runScrape(StartScrapeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // 1. Generate the Run record + dynamic 48-char token
        $run = $this->runs->create($validated);

        try {
            // 2. Dispatch request to Node.js Scraper on correct '/scrape' endpoint
            $response = Http::timeout(10)
                ->post(
                    rtrim(config('services.node_scraper.url', 'http://localhost:3000'), '/') . '/scrape',
                    [
                        ...$validated,
                        'callback_url' => route('book-scraper.callback'),
                        'run_token'    => $run->token, // The transient secure token
                    ]
                );

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
}
