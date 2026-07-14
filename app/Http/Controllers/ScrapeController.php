<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeCallbackRequest;
use App\Http\Requests\StartScrapeRequest;
use App\Services\ScrapeCallbackService;
use App\Services\ScrapeJobService;
use App\Services\ScrapeRunService;
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
        $run = $this->runs->create($validated);

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => config('services.node_scraper.api_key'),
            ])
                ->timeout(10)
                ->post(
                    config('services.node_scraper.url') . '/scrape/run',
                    [
                        ...$validated,
                        'callback_url' => route('book-scraper.callback'),
                        'run_token'    => $run->token,
                    ]
                );

            if ($response->failed()) {
                Log::error('Node scraper returned an error.', [
                    'body' => $response->body(),
                ]);

                $this->runs->fail($run, $response->body());

                return response()->json([
                    'message' => 'Failed to start scrape.',
                    'details' => $response->json(),
                ], $response->status());
            }

            $body = $response->json();
            $jobTokens = $body['job_tokens'] ?? [];

            $this->jobs->createJobs($run, $jobTokens);

            $jobsTotal = $body['jobs_total'] ?? count($jobTokens);
            $this->runs->start($run, $jobsTotal);

            return response()->json([
                'message'    => 'Scrape started successfully.',
                'run_id'     => $run->id,
                'jobs_total' => $jobsTotal,
            ], 202);
        } catch (Throwable $e) {
            Log::error('Unable to contact Node scraper.', [
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
                'message' => 'Callback processed.',
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
