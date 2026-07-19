<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookPriceHistory extends Model
{
    protected $fillable = [
        'book_id',
        'price',
        'recorded_at',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function priceHistory()
    {
        return $this->hasMany(BookPriceHistory::class);
    }
}
