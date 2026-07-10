<?php
// app/Models/ScrapeRunJob.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeRunJob extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    public function scrapeRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class);
    }
}
