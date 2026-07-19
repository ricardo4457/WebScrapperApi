<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    protected $fillable = [
        'title',
        'publisher',
        'authors',
        'cover_path',
        'price',
        'discipline',
        'type',
    ];

    protected $casts = [
        'price'   => 'decimal:2',
        'authors' => 'array',
    ];
    public function schoolBooks()
    {
        return $this->hasMany(SchoolBook::class);
    }

    public function schools()
    {
        return $this->belongsToMany(School::class, 'school_books')
            ->withPivot('year', 'teaching_cycle')
            ->withTimestamps();
    }
}
