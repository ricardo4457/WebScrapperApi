<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScrapeCallbackRequest;
use App\Http\Requests\StartScrapeRequest;
use App\Http\Services\ScrapeCallbackService;
use App\Http\Services\ScrapeJobService;
use App\Http\Services\ScrapeRunService;
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

    public function monitor(int $runId)
{
    $run = \App\Models\ScrapeRun::findOrFail($runId);
    return response()->json([
        'status' => $run->status,
        'progresso' => "{$run->jobs_done} de {$run->jobs_total} concluídos",
        'erros' => $run->jobs_failed,
        'ultima_atualizacao' => $run->updated_at
    ]);
}
}
