<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->string('token', 64)->unique()->nullable()->after('id');
            $table->string('status')->default('pending')->change();
            $table->string('external_run_id')->nullable()->after('status');
            $table->json('params')->nullable();
            $table->json('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
