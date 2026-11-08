<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number', 64)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('dataset_key', 64);
            $table->jsonb('filter_json');
            $table->jsonb('column_json');
            $table->jsonb('scope_snapshot_json')->nullable();
            $table->string('locale', 16)->default('bn');
            $table->string('format', 16)->default('XLSX'); // XLSX, PDF
            $table->integer('row_count')->nullable();
            $table->timestamp('cutoff_at')->nullable();
            $table->string('status', 32)->default('QUEUED'); // QUEUED, RUNNING, READY, FAILED, CANCELLING, CANCELLED, EXPIRED
            $table->string('storage_key', 512)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
