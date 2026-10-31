<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('fee_adjustment_requests')->restrictOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestampTz('applied_at');
            $table->jsonb('snapshot_after');
            $table->timestampsTz();

            $table->index('invoice_item_id', 'idx_fee_adjustments_item');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_amount_check CHECK (amount > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_adjustments');
    }
};
