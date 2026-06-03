<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolBook extends Model
{
    protected $fillable = [
        'book_id',
        'school_id',
        'year',
        'teaching_cycle',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
