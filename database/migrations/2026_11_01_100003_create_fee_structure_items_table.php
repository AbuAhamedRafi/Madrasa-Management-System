<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_structure_id')->constrained('fee_structures')->cascadeOnDelete();
            $table->foreignId('fee_category_id')->constrained('fee_categories')->restrictOnDelete();
            $table->string('cadence', 32)->default('PER_ENROLLMENT');
            $table->decimal('amount', 14, 2);
            $table->boolean('admission_required')->default(false);
            $table->jsonb('due_rule')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['fee_structure_id', 'fee_category_id', 'cadence'], 'uq_fee_structure_category_cadence');
            $table->index('fee_structure_id', 'idx_fee_structure_items_structure');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_structure_items ADD CONSTRAINT fee_structure_items_cadence_check CHECK (cadence IN ('PER_ENROLLMENT', 'MONTHLY', 'TERMLY', 'ANNUAL', 'ONE_TIME'))");
            DB::statement("ALTER TABLE fee_structure_items ADD CONSTRAINT fee_structure_items_amount_check CHECK (amount >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structure_items');
    }
};

