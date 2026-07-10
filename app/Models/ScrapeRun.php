<?php
// app/Models/ScrapeRun.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function jobs(): HasMany
    {
        return $this->hasMany(ScrapeRunJob::class);
    }
}
