<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();

            $table->string('token', 64)->unique();

            $table->enum('status', [
                'pending',
                'running',
                'completed',
                'failed'
            ])->default('pending');

            $table->string('external_run_id')->nullable();

            $table->json('params')->nullable();

            // Counters for tracking progress
            $table->unsignedInteger('jobs_total')->default(0);
            $table->unsignedInteger('jobs_done')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);

            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
