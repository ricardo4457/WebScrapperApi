<?php

namespace App\Http\Services;

use App\Models\ScrapeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScrapeCallbackService
{
    public function __construct(
        protected ScrapeRunService $runs,
        protected ScrapeJobService $jobs,
        protected BookImportService $books,
    ) {
    }

    /**
     * Process incoming scraper callback.
     */
    public function process(array $validated): void
    {
        // 1. Find the master run by its secure token
        $run = ScrapeRun::where('token', $validated['run_token'])->firstOrFail();

        // 2. Prevent reprocessing duplicate or late webhooks if the run has ended
        if (in_array($run->status, ['completed', 'failed'])) {
            return;
        }

        // 3. Process the job and import books inside a database transaction
        DB::transaction(function () use ($validated, $run) {
            // Lock the job row to prevent concurrent race conditions
            $job = $this->jobs->lock($run, $validated['job_token']);

            if (!$job) {
                Log::warning('Unknown job token received in webhook callback.', [
                    'run_id'    => $run->id,
                    'job_token' => $validated['job_token'],
                ]);
                return;
            }

            // Skip processing if this specific job has already completed or failed
            if ($this->jobs->isFinished($job)) {
                return;
            }

            // 4. Update the job status and run counters based on results
            if ($validated['status'] === 'failed') {
                $this->jobs->fail($job, $validated['error'] ?? null);
                $this->runs->incrementFailed($run);
            } else {
                // Import the scraped books into our database
                $this->books->import($validated['books'] ?? []);

                $this->jobs->complete($job);
                $this->runs->incrementCompleted($run);
            }

            // 5. Finalize the master ScrapeRun if all associated jobs are finished
            $this->runs->finishIfComplete($run);
        });
    }
}
