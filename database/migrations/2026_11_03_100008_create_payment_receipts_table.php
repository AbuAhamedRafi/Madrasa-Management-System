<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 64)->unique();
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_slip_id')->constrained('payment_slips')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('status', 32)->default('VALID'); // VALID, REVERSED
            $table->decimal('receipt_amount', 12, 2);
            $table->string('verification_token_hash', 128)->unique();
            $table->text('verification_token_encrypted')->nullable();
            $table->jsonb('snapshot_payload')->nullable();
            $table->integer('print_count')->default(1);
            $table->timestamp('last_printed_at')->nullable();
            $table->text('last_reprint_reason')->nullable();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};

