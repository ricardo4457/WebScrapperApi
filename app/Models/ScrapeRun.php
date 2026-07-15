<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeRun extends Model
{
    protected $fillable = [
        'token',
        'status',
        'external_run_id',
        'params',
        'jobs_total',
        'jobs_done',
        'jobs_failed',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'params' => 'array', // Automatically casts JSON DB field to PHP array
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the child jobs associated with this scrape run.
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(ScrapeRunJob::class, 'scrape_run_id');
    }
}
