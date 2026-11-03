<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_number', 64)->unique();
            $table->foreignId('cashier_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('counter_code', 64);
            $table->string('status', 32)->default('OPEN'); // OPEN, CLOSED, UNDER_REVIEW, RECONCILED
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float_amount', 12, 2)->default(0.00);
            $table->decimal('expected_cash_amount', 12, 2)->default(0.00);
            $table->decimal('counted_cash_amount', 12, 2)->nullable();
            $table->decimal('variance_amount', 12, 2)->nullable();
            $table->jsonb('denomination_breakdown')->nullable();
            $table->text('closing_notes')->nullable();
            $table->text('variance_reason')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->timestamps();

            $table->index(['cashier_user_id', 'status']);
            $table->index(['status', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};

