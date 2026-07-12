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
        Schema::create('scrape_run_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('scrape_run_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('job_token', 64)->unique();
            $table->string('bullmq_job_id')->nullable()->unique();

            $table->enum('status', [
                'pending',
                'running',
                'completed',
                'failed'
            ])->default('pending');

            $table->text('error_message')->nullable();
            $table->timestamp('reported_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_run_jobs');
    }
};
