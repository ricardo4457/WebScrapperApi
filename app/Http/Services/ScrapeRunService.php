<?php

namespace App\Http\Services;

use App\Models\ScrapeRun;
use Illuminate\Support\Str;

class ScrapeRunService
{
    /**
     * Create a new scrape run.
     */
    public function create(array $validated): ScrapeRun
    {
        return ScrapeRun::create([
            'token'      => Str::random(48),
            'strategy'   => $validated['strategy'],
            'status'     => 'pending',
            'params'     => $validated,
        ]);
    }

    /**
     * Mark the run as active and set total jobs.
     */
    public function start(ScrapeRun $run, int $jobsTotal): void
    {
        $run->update([
            'status'     => 'running',
            'jobs_total' => $jobsTotal,
            'started_at' => now(),
        ]);
    }

    /**
     * Increment the completed jobs counter.
     */
    public function incrementCompleted(ScrapeRun $run): void
    {
        $run->increment('jobs_done');
    }

    /**
     * Increment the failed jobs counter.
     */
    public function incrementFailed(ScrapeRun $run): void
    {
        $run->increment('jobs_failed');
    }

    /**
     * Mark the run as failed.
     */
    public function fail(ScrapeRun $run, ?string $error = null): void
    {
        $run->update([
            'status'        => 'failed',
            'error_message' => $error,
            'completed_at'  => now(),
        ]);
    }

    /**
     * Finalize the run if all jobs have finished reporting.
     */
    public function finishIfComplete(ScrapeRun $run): void
    {
        $run->refresh();

        if (($run->jobs_done + $run->jobs_failed) < $run->jobs_total) {
            return;
        }

        $run->update([
            'status'       => $run->jobs_done > 0 ? 'completed' : 'failed',
            'completed_at' => now(),
        ]);
    }
}
