<?php

namespace App\Http\Services;

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
     * Mark job as completed.
     */
    public function complete(ScrapeRunJob $job): void
    {
        $job->update([
            'status'      => 'completed',
            'reported_at' => now(),
        ]);
    }

    /**
     * Mark job as failed.
     */
    public function fail(ScrapeRunJob $job, ?string $error = null): void
    {
        $job->update([
            'status'        => 'failed',
            'error_message' => $error,
            'reported_at'   => now(),
        ]);
    }

    /**
     * Check if the job has already reached a terminal state (completed or failed).
     * Useful to prevent reprocessing retried callback webhooks.
     */
    public function isFinished(ScrapeRunJob $job): bool
    {
        return in_array($job->status, ['completed', 'failed']);
    }
}
