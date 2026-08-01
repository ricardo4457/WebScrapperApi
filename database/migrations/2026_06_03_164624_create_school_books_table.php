<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_books', function (Blueprint $table) {
            $table->id();

            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            $table->string('year', 10);              // ex: 4.º, 10.º
            $table->string('teaching_cycle', 80);    // ex: Ensino Básico (3º Ciclo)
            $table->string('course', 120)->nullable();

            $table->timestamps();

            $table->unique(
                ['school_id', 'book_id', 'year', 'teaching_cycle', 'course'],
                'school_books_unique_link'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_books');
    }
};
