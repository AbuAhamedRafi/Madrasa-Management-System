<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_reference', 64)->unique();
            $table->foreignId('payment_slip_id')->constrained('payment_slips')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->string('payment_method', 32)->default('CASH');
            $table->string('status', 32)->default('POSTED'); // POSTED, REVERSED
            $table->decimal('amount_received', 12, 2);
            $table->decimal('allocated_amount', 12, 2);
            $table->decimal('cash_tendered_amount', 12, 2);
            $table->decimal('change_returned_amount', 12, 2)->default(0.00);
            $table->foreignId('collected_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->string('counter_code', 64)->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('admission_approved_triggered')->default(false);
            $table->string('idempotency_key', 128)->unique();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['student_id', 'collected_at']);
            $table->index(['cashier_shift_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

