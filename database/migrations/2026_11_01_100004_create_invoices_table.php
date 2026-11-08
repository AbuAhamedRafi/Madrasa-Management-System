<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 32)->unique();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->constrained('fee_structures')->nullOnDelete();
            $table->string('source_type', 32)->default('ENROLLMENT_ADMISSION');
            $table->string('source_key', 160)->unique();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('gross_amount', 14, 2)->default(0.00);
            $table->decimal('adjustment_amount', 14, 2)->default(0.00);
            $table->decimal('net_amount', 14, 2)->default(0.00);
            $table->boolean('zero_charge_policy')->default(false);
            $table->string('status', 20)->default('DRAFT');
            $table->timestampTz('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['student_id', 'academic_period_id', 'status'], 'idx_invoices_student_period_status');
            $table->index(['enrollment_id', 'status'], 'idx_invoices_enrollment_status');
            $table->index('status', 'idx_invoices_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('DRAFT', 'ISSUED', 'VOID'))");
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_source_type_check CHECK (source_type IN ('ENROLLMENT_ADMISSION', 'MONTHLY_RUN', 'EXAM', 'MANUAL', 'OTHER'))");
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_check CHECK (gross_amount >= 0 AND adjustment_amount >= 0 AND net_amount >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

