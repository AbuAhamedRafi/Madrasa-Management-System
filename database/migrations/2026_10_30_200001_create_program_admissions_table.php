<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->foreignId('academic_cohort_id')->nullable()->constrained('academic_cohorts')->restrictOnDelete();
            $table->string('entry_type', 32)->default('REGULAR');
            $table->string('status', 32)->default('PENDING_ACTIVATION')->index();
            $table->timestampTz('entered_at');
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('student_id', 'idx_program_admissions_student');
            $table->index(['academic_program_id', 'status'], 'idx_program_admissions_prog_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE program_admissions ADD CONSTRAINT program_admissions_entry_type_check CHECK (entry_type IN ('REGULAR', 'TRANSFER_IN', 'LATERAL_ENTRY'))");
            DB::statement("ALTER TABLE program_admissions ADD CONSTRAINT program_admissions_status_check CHECK (status IN ('PENDING_ACTIVATION', 'ACTIVE', 'COMPLETED', 'CANCELLED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('program_admissions');
    }
};

