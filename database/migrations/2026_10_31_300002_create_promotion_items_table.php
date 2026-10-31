<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('promotion_batches')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('source_enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('target_offering_section_id')->constrained('offering_sections')->restrictOnDelete();
            $table->string('action', 16)->default('PROMOTE');
            $table->boolean('academic_eligibility_confirmed')->default(false);
            $table->foreignId('eligibility_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('eligibility_confirmed_at')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->foreignId('target_enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampsTz();

            $table->unique(['batch_id', 'source_enrollment_id'], 'uq_batch_source_enrollment');
            $table->index(['batch_id', 'status'], 'idx_promotion_items_batch_status');
            $table->index('student_id', 'idx_promotion_items_student');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE promotion_items ADD CONSTRAINT promotion_items_action_check CHECK (action IN ('PROMOTE', 'REPEAT'))");
            DB::statement("ALTER TABLE promotion_items ADD CONSTRAINT promotion_items_status_check CHECK (status IN ('PENDING', 'ELIGIBLE', 'PROCESSING', 'SUCCEEDED', 'FAILED', 'SKIPPED', 'RETRY_QUEUED'))");
            DB::statement("CREATE UNIQUE INDEX uq_source_enrollment_succeeded_promotion ON promotion_items (source_enrollment_id) WHERE status = 'SUCCEEDED'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_items');
    }
};

