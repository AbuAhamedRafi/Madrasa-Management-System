<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->string('before_state', 32)->nullable();
            $table->string('after_state', 32)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->jsonb('context_redacted_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_events');
    }
};
