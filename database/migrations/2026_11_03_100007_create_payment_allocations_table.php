<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_reversal_id')->nullable()->constrained('payment_reversals')->nullOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->string('direction', 32)->default('CREDIT'); // CREDIT, DEBIT_REVERSAL
            $table->decimal('allocated_amount', 12, 2);
            $table->boolean('admission_required')->default(false);
            $table->decimal('item_balance_before', 12, 2);
            $table->decimal('item_balance_after', 12, 2);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['invoice_id', 'invoice_item_id']);
            $table->index(['payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};

