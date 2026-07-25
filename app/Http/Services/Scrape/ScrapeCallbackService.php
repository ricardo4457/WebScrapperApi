<?php

namespace App\Http\Services\Scrape;

use App\Models\ScrapeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Http\Services\Scrape\ScrapeRunService;
use App\Http\Services\Scrape\ScrapeJobService;
use App\Http\Services\Book\BookImportService;

class ScrapeCallbackService
{
    public function __construct(
        protected ScrapeRunService $runs,
        protected ScrapeJobService $jobs,
        protected BookImportService $books,
    ) {}

    /**
     * Handles streamed callbacks from the scraper worker.
     *
     * Partial callbacks import books and update progress.
     * The final callback completes the job and, once all jobs finish,
     * finalizes the scrape run.
     */
    public function process(array $validated): void
    {
        // Log the incoming payload for debugging purposes
        Log::info('[ScrapeCallback] Received webhook payload.', [
            'run_token' => $validated['run_token'] ?? 'missing',
            'job_token' => $validated['job_token'] ?? 'missing',
            'status'    => $validated['status'] ?? 'missing',
            'final'     => $validated['final'] ?? false,
            'books_count' => isset($validated['books']) ? count($validated['books']) : 0,
        ]);

        $run = ScrapeRun::where('token', $validated['run_token'])->firstOrFail();

        if (in_array($run->status, ['completed', 'failed'])) {
            Log::warning('[ScrapeCallback] Webhook for finished run ignored.', ['run_token' => $run->token]);
            return;
        }

        DB::transaction(function () use ($validated, $run) {
            $job = $this->jobs->lock($run, $validated['job_token']);

            if (!$job) {
                Log::error('[ScrapeCallback] Job token NOT FOUND.', ['job_token' => $validated['job_token']]);
                return;
            }

            if ($this->jobs->isFinished($job)) {
                Log::info('[ScrapeCallback] Job already finished, skipping.', ['job_id' => $job->id]);
                return;
            }

            $isFinal = (bool) ($validated['final'] ?? false);

            if (!$isFinal) {
                $this->processPartialBatch($job, $validated);
                return;
            }

            $this->processFinal($run, $job, $validated);
        });
    }

    /**
     * Imports one partial batch's books and accumulates progress on the job.
     */
    private function processPartialBatch($job, array $validated): void
    {
        $this->jobs->markRunning($job);

        Log::info('[ScrapeCallback] Importing partial batch for job.', ['job_id' => $job->id]);
        Log::debug('[ScrapeCallback] Batch payload structure:', ['first_entry' => $validated['books'][0] ?? 'empty']);

        $report = $this->books->import($validated['books'] ?? []);
        $this->jobs->accumulateProgress($job, $report);

        Log::info('[ScrapeCallback] Partial batch import report.', [
            'job_id'   => $job->id,
            'imported' => $report['imported'],
            'skipped'  => $report['skipped'],
        ]);
    }

    /**
     * Finalizes the job 
     */
    private function processFinal(ScrapeRun $run, $job, array $validated): void
    {
        $scrapeErrors = $validated['books'] ?? [];

        if ($validated['status'] === 'failed') {
            Log::error('[ScrapeCallback] Job reported failure.', [
                'job_id' => $job->id,
                'error'  => $validated['error'] ?? 'No error message provided',
            ]);
            $this->jobs->fail($job, $validated['error'] ?? null, $scrapeErrors);
            $this->runs->incrementFailed($run);
        } else {
            $job->refresh();

            if ($job->books_imported === 0 && $job->books_skipped > 0) {
                $this->jobs->fail($job, 'No books were imported.', $scrapeErrors);
                $this->runs->incrementFailed($run);
            } else {
                $this->jobs->complete($job, $scrapeErrors);
                $this->runs->incrementCompleted($run);
            }
        }

        Log::info('[ScrapeCallback] Job finalized.', [
            'job_id'         => $job->id,
            'status'         => $job->fresh()->status,
            'books_imported' => $job->books_imported,
            'books_skipped'  => $job->books_skipped,
            'scrape_errors'  => count($scrapeErrors),
        ]);

        $this->runs->finishIfComplete($run);
    }
}
