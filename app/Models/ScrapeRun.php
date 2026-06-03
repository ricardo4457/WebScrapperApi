<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapeRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'strategy',
        'status',
        'params',
        'jobs_total',
        'jobs_done',
        'jobs_failed',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'params'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
