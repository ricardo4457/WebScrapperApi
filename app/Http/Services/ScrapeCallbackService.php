<?php

namespace App\Services;

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

    public function process(array $validated): void
    {
        $run = ScrapeRun::where('token', $validated['run_token'])
            ->firstOrFail();

        if (in_array($run->status, ['completed', 'failed'])) {
            return;
        }

        DB::transaction(function () use ($validated, $run) {

            $job = $this->jobs->lock(
                $run,
                $validated['job_token']
            );

            if (!$job) {

                Log::warning('Unknown job token received.', [
                    'run_id' => $run->id,
                    'job_token' => $validated['job_token'],
                ]);

                return;
            }

            if ($this->jobs->alreadyProcessed($job)) {
                return;
            }

            if ($validated['status'] === 'failed') {

                $this->jobs->fail(
                    $job,
                    $validated['error'] ?? null
                );

                $this->runs->incrementFailed($run);

            } else {

                $this->books->import(
                    $validated['books']
                );

                $this->jobs->complete($job);

                $this->runs->incrementCompleted($run);
            }

            $this->runs->finishIfComplete($run);
        });
    }
}
