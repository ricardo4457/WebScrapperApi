<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapeRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'error_message' => 'array',
        'completed_at' => 'datetime',
    ];
}
