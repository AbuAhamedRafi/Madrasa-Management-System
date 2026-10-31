<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('fee_category_id')->constrained('fee_categories')->restrictOnDelete();
            $table->foreignId('fee_structure_item_id')->nullable()->constrained('fee_structure_items')->nullOnDelete();
            $table->unsignedInteger('source_version')->nullable();
            $table->string('description_bn', 255);
            $table->string('description_en', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0.00);
            $table->decimal('net_amount', 14, 2);
            $table->boolean('admission_required')->default(false);
            $table->date('billing_month')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('invoice_id', 'idx_invoice_items_invoice');
            $table->index(['invoice_id', 'admission_required'], 'idx_invoice_items_adm_req');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_amounts_check CHECK (quantity >= 1 AND unit_amount >= 0 AND gross_amount >= 0 AND discount_amount >= 0 AND net_amount >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
