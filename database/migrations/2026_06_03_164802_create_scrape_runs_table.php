<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->string('strategy');
            $table->enum('status', ['pending', 'running', 'completed', 'failed']);
            $table->json('params');
            $table->integer('jobs_total')->default(0);
            $table->integer('jobs_done')->default(0);
            $table->integer('jobs_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
