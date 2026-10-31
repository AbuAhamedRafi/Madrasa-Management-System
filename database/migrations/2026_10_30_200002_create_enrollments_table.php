<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_admission_id')->constrained('program_admissions')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('academic_offering_id')->constrained('academic_offerings')->restrictOnDelete();
            $table->foreignId('offering_section_id')->constrained('offering_sections')->restrictOnDelete();
            $table->string('enrollment_type', 32)->default('NEW');
            $table->string('status', 32)->default('PENDING_BILLING')->index();
            $table->boolean('billing_cleared')->default(false);
            $table->boolean('payment_cleared')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampTz('submitted_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['offering_section_id', 'status'], 'idx_enrollments_section_status');
            $table->index(['academic_period_id', 'status'], 'idx_enrollments_period_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_enrollment_type_check CHECK (enrollment_type IN ('NEW', 'CONTINUING'))");
            DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_status_check CHECK (status IN ('DRAFT', 'PENDING_BILLING', 'PENDING_PAYMENT', 'APPROVED', 'REJECTED', 'CANCELLED'))");
            DB::statement("CREATE UNIQUE INDEX uq_student_active_period_enrollment ON enrollments (student_id, academic_period_id) WHERE status IN ('DRAFT', 'PENDING_BILLING', 'PENDING_PAYMENT', 'APPROVED')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};

