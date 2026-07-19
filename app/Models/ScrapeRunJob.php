<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeRunJob extends Model
{
    protected $fillable = [
        'scrape_run_id',
        'job_token',
        'bullmq_job_id',
        'status',
        'error_message',
        'reported_at',
        'books_imported',
        'books_skipped',
        'import_errors',
    ];

    protected $casts = [
        'reported_at'    => 'datetime',
        'import_errors'  => 'array',
    ];


    public function run(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class, 'scrape_run_id');
    }
}
