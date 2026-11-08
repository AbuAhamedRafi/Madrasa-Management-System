<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->string('reversal_reference', 64)->unique();
            $table->foreignId('reversal_request_id')->unique()->constrained('payment_reversal_requests')->cascadeOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->decimal('reversed_amount', 12, 2);
            $table->string('admission_review_flag', 32)->default('NONE'); // NONE, FLAGGED_FOR_REVIEW
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('reversed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payment_id']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};

