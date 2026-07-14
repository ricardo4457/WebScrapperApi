<?php

namespace App\Services;

use App\Models\ScrapeRun;
use App\Models\ScrapeRunJob;

class ScrapeJobService
{
    /**
     * Register multiple jobs linked to a run.
     */
    public function createJobs(ScrapeRun $run, array $jobTokens): void
    {
        foreach ($jobTokens as $jobToken) {
            ScrapeRunJob::create([
                'scrape_run_id' => $run->id,
                'job_token'     => $jobToken,
                'status'        => 'pending',
            ]);
        }
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
     */
    public function lock(ScrapeRun $run, string $jobToken): ?ScrapeRunJob
    {
        return ScrapeRunJob::where('scrape_run_id', $run->id)
            ->where('job_token', $jobToken)
            ->lockForUpdate()
            ->first();
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
     * Check if job status is no longer pending.
     */
    public function alreadyProcessed(ScrapeRunJob $job): bool
    {
        return $job->status !== 'pending';
    }
}
