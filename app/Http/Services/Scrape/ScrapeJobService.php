<?php

namespace App\Http\Services\Scrape;

use App\Models\ScrapeRun;
use App\Models\ScrapeRunJob;

class ScrapeJobService
{
    /**
     * Register multiple jobs linked to a run using bulk insertion.
     * Optimized to execute a single query instead of N queries in a loop.
     */
    public function createJobs(ScrapeRun $run, array $jobTokens): void
    {
        $jobs = array_map(fn($token) => [
            'job_token' => $token, // Usa o token que veio do array original
            'status'    => 'pending',
        ], $jobTokens);

        $run->jobs()->createMany($jobs);
    }

    /**
     * Find a job by run and token.
     */
    public function find(ScrapeRun $run, string $jobToken): ?ScrapeRunJob
    {
        return ScrapeRunJob::where('scrape_run_id', $run->id)
            ->where('job_token', $jobToken)
            ->first();
    }

    /**
     * Lock job row for update inside a transaction.
     * Make sure to call this method inside a DB::transaction() block.
     */
    public function lock(ScrapeRun $run, string $jobToken): ?ScrapeRunJob
    {
        return ScrapeRunJob::where('scrape_run_id', $run->id)
            ->where('job_token', $jobToken)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Mark job as running and map the corresponding BullMQ Job ID.
     */
    public function start(ScrapeRunJob $job, string $bullmqJobId): void
    {
        $job->update([
            'status'        => 'running',
            'bullmq_job_id' => $bullmqJobId,
            'reported_at'   => now(),
        ]);
    }

    /**
     * Transitions a job from 'pending' to 'running' on its first partial callback.
     */
    public function markRunning(ScrapeRunJob $job): void
    {
        if ($job->status !== 'pending') {
            return;
        }

        $job->update([
            'status'      => 'running',
            'reported_at' => now(),
        ]);
    }

    /**
     * Adds one partial batch's import report to the job's running totals.
     *

     */
    public function accumulateProgress(ScrapeRunJob $job, array $report, int $attempt): void
    {
        if ($attempt < $job->last_attempt_seen) {
            return;
        }

        $isNewAttempt = $attempt > $job->last_attempt_seen;

        $errors = $isNewAttempt ? [] : ($job->import_errors ?? []);
        if (!empty($report['errors'])) {
            $errors = [...$errors, ...$report['errors']];
        }

        $job->update([
            'books_imported'    => ($isNewAttempt ? 0 : $job->books_imported) + ($report['imported'] ?? 0),
            'books_skipped'     => ($isNewAttempt ? 0 : $job->books_skipped) + ($report['skipped'] ?? 0),
            'import_errors'     => $errors ?: null,
            'last_attempt_seen' => $attempt,
            'reported_at'       => now(),
        ]);
    }

    /**
     * Mark job as completed.
     *
     * Ignored if attempt is lower than the last attempt already recorded
     */
    public function complete(ScrapeRunJob $job, array $scrapeErrors = [], int $attempt = 0): bool
    {
        if ($attempt < $job->last_attempt_seen) {
            return false;
        }

        $job->update([
            'status'            => 'completed',
            'reported_at'       => now(),
            'last_attempt_seen' => $attempt,
            'import_errors'     => $this->mergeScrapeErrors($job, $scrapeErrors),
        ]);

        return true;
    }

    /**
     * Mark job as failed.
     */
    public function fail(ScrapeRunJob $job, ?string $error = null, array $scrapeErrors = [], int $attempt = 0): bool
    {
        if ($attempt < $job->last_attempt_seen) {
            return false;
        }

        $job->update([
            'status'            => 'failed',
            'error_message'     => $error,
            'reported_at'       => now(),
            'last_attempt_seen' => $attempt,
            'import_errors'     => $this->mergeScrapeErrors($job, $scrapeErrors),
        ]);

        return true;
    }

    /**
     * Appends per-school scrape failures
     */
    private function mergeScrapeErrors(ScrapeRunJob $job, array $scrapeErrors): ?array
    {
        if (empty($scrapeErrors)) {
            return $job->import_errors ?: null;
        }

        $formatted = array_map(fn($entry) => [
            'school' => $entry['school']['name'] ?? null,
            'item'   => null,
            'reason' => $entry['error'] ?? 'Unknown scrape error.',
        ], $scrapeErrors);

        return [...($job->import_errors ?? []), ...$formatted];
    }

    /**
     * Check if the job has already reached a terminal state.
     */
    public function isFinished(ScrapeRunJob $job): bool
    {
        return in_array($job->status, ['completed', 'failed']);
    }
}
