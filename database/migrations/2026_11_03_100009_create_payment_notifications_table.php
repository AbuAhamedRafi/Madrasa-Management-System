<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_receipt_id')->constrained('payment_receipts')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->string('channel', 24)->default('SMS');
            $table->string('recipient_name', 191)->nullable();
            $table->text('recipient_phone_encrypted')->nullable();
            $table->string('recipient_phone_masked', 32)->nullable();
            $table->string('status', 32)->default('QUEUED'); // QUEUED, SENT, DELIVERED, FAILED, SKIPPED_NO_CONSENT, SKIPPED_NO_PHONE
            $table->string('template_code', 64)->default('PAYMENT_RECEIPT_BN');
            $table->text('message_preview')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('last_retried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payment_id']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
    }
};

