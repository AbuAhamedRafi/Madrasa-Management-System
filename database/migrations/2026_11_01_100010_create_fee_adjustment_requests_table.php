<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->string('adjustment_type', 32)->default('DISCOUNT');
            $table->decimal('requested_amount', 14, 2);
            $table->text('reason');
            $table->string('supporting_reference', 100)->nullable();
            $table->string('state', 24)->default('PENDING');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['invoice_item_id', 'state'], 'idx_fee_adj_requests_item_state');
            $table->index('state', 'idx_fee_adj_requests_state');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_adjustment_requests ADD CONSTRAINT fee_adjustment_requests_type_check CHECK (adjustment_type IN ('DISCOUNT', 'SCHOLARSHIP', 'WAIVER'))");
            DB::statement("ALTER TABLE fee_adjustment_requests ADD CONSTRAINT fee_adjustment_requests_state_check CHECK (state IN ('PENDING', 'APPROVED', 'REJECTED', 'APPLIED'))");
            DB::statement("ALTER TABLE fee_adjustment_requests ADD CONSTRAINT fee_adjustment_requests_amount_check CHECK (requested_amount > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_adjustment_requests');
    }
};

