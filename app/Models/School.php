<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    protected $fillable = [
        'district',
        'city',
        'name',
    ];

    public function schoolBooks()
    {
        return $this->hasMany(SchoolBook::class);
    }

    public function books()
    {
        return $this->belongsToMany(Book::class, 'school_books')
                    ->withPivot('year', 'teaching_cycle')
                    ->withTimestamps();
    }
}
