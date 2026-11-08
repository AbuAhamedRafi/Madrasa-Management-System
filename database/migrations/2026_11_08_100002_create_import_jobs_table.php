<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number', 64)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 32); // CREATE_ONLY, MATCH_UPDATE, ENROLL_ONLY
            $table->string('template_code', 64);
            $table->string('template_version', 16)->default('1.0');
            $table->string('locale', 16)->default('bn');
            $table->string('source_name', 255);
            $table->string('storage_key', 512);
            $table->string('sha256', 64);
            $table->bigInteger('byte_size')->default(0);
            $table->jsonb('detected_sheets_json')->nullable();
            $table->string('state', 32)->default('UPLOADED');
            $table->integer('lock_version')->default(1);
            $table->integer('mapping_version')->default(0);
            $table->integer('reviewed_version')->default(0);
            $table->string('preview_token_hash', 64)->nullable();
            $table->string('preview_state_hash', 64)->nullable();
            $table->timestamp('preview_expires_at')->nullable();
            $table->integer('total_rows')->default(0);
            $table->integer('ready_rows')->default(0);
            $table->integer('review_rows')->default(0);
            $table->integer('invalid_rows')->default(0);
            $table->integer('committed_rows')->default(0);
            $table->integer('skipped_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->string('result_storage_key', 512)->nullable();
            $table->string('result_sha256', 64)->nullable();
            $table->jsonb('scope_json')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
