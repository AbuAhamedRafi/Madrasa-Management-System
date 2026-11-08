<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->string('sheet', 128);
            $table->integer('row_number'); // 1-based source Excel row number
            $table->string('row_hash', 64);
            $table->jsonb('raw_payload_json');
            $table->jsonb('normalized_payload_json')->nullable();
            $table->string('status', 32)->default('PENDING'); // PENDING, READY, REVIEW_REQUIRED, INVALID, COMMITTED, SKIPPED, FAILED, CANCELLED
            $table->string('action', 32)->default('CREATE'); // CREATE, MATCH_UPDATE, ENROLL, SKIP
            $table->foreignId('target_student_pk')->nullable()->constrained('students')->nullOnDelete();
            $table->string('target_student_version', 64)->nullable();
            $table->jsonb('candidate_matches_json')->nullable();
            $table->jsonb('diff_json')->nullable();
            $table->jsonb('decision_json')->nullable();
            $table->jsonb('validation_summary_json')->nullable();
            $table->jsonb('outcome_json')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['import_job_id', 'sheet', 'row_number'], 'import_rows_job_sheet_row_unique');
            $table->index(['import_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
