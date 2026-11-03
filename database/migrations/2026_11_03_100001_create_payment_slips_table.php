<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_slips', function (Blueprint $table) {
            $table->id();
            $table->string('slip_number', 64)->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('academic_period_id')->constrained('academic_periods')->cascadeOnDelete();
            $table->string('status', 32)->default('UNPAID'); // UNPAID, PARTIALLY_COLLECTED, COLLECTED, EXPIRED, VOIDED, SUPERSEDED
            $table->decimal('slip_amount', 12, 2);
            $table->decimal('collected_amount', 12, 2)->default(0.00);
            $table->decimal('remaining_amount', 12, 2);
            $table->timestamp('expires_at')->nullable();
            $table->string('verification_token_hash', 128)->unique();
            $table->text('verification_token_encrypted')->nullable();
            $table->jsonb('snapshot_payload')->nullable();
            $table->integer('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('superseded_by_slip_id')->nullable()->constrained('payment_slips')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['student_id', 'status']);
            $table->index(['enrollment_id', 'status']);
            $table->index(['verification_token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_slips');
    }
};

