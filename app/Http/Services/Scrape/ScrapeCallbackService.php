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
     * Process incoming scraper callback.
     */
    public function process(array $validated): void
    {
        // Log do payload completo recebido do webhook
        Log::info('[ScrapeCallback] Received webhook payload.', [
            'run_token' => $validated['run_token'] ?? 'missing',
            'job_token' => $validated['job_token'] ?? 'missing',
            'status'    => $validated['status'] ?? 'missing',
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

            if ($validated['status'] === 'failed') {
                Log::error('[ScrapeCallback] Job reported failure.', [
                    'job_id' => $job->id,
                    'error'  => $validated['error'] ?? 'No error message provided'
                ]);
                $this->jobs->fail($job, $validated['error'] ?? null);
                $this->runs->incrementFailed($run);
            } else {
                Log::info('[ScrapeCallback] Importing books for job.', ['job_id' => $job->id]);

                // LOG DE ESTRUTURA: Ajuda a ver se o Node está a enviar o que esperas
                Log::debug('[ScrapeCallback] Books payload structure:', ['first_entry' => $validated['books'][0] ?? 'empty']);

                $this->books->import($validated['books'] ?? []);

                $this->jobs->complete($job);
                $this->runs->incrementCompleted($run);
            }

            $this->runs->finishIfComplete($run);
        });
    }
}
