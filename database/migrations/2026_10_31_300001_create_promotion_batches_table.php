<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 32)->unique();
            $table->string('mode', 16)->default('BULK');
            $table->string('action', 16)->default('PROMOTE');
            $table->foreignId('source_academic_period_id')->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('source_offering_section_id')->nullable()->constrained('offering_sections')->restrictOnDelete();
            $table->foreignId('target_academic_period_id')->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('target_academic_offering_id')->constrained('academic_offerings')->restrictOnDelete();
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index('status', 'idx_promotion_batches_status');
            $table->index('source_academic_period_id', 'idx_promotion_batches_source_period');
            $table->index('target_academic_period_id', 'idx_promotion_batches_target_period');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE promotion_batches ADD CONSTRAINT promotion_batches_mode_check CHECK (mode IN ('INDIVIDUAL', 'BULK'))");
            DB::statement("ALTER TABLE promotion_batches ADD CONSTRAINT promotion_batches_action_check CHECK (action IN ('PROMOTE', 'REPEAT'))");
            DB::statement("ALTER TABLE promotion_batches ADD CONSTRAINT promotion_batches_status_check CHECK (status IN ('DRAFT', 'PREVIEWED', 'QUEUED', 'RUNNING', 'PAUSING', 'PAUSED', 'COMPLETED', 'COMPLETED_WITH_ERRORS', 'CANCELLED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_batches');
    }
};

