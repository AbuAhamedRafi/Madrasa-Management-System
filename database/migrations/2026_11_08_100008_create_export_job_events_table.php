<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('export_job_id')->constrained('export_jobs')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->jsonb('context_redacted_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['export_job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_job_events');
    }
};
