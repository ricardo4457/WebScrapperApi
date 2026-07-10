<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScrapeRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'error_message' => 'array',
        'completed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($scrapeRun) {
            if (empty($scrapeRun->token)) {
                $scrapeRun->token = Str::random(48);
            }
        });
    }
}
