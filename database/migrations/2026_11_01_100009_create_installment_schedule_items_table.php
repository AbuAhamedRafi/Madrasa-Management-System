<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->unsignedInteger('installment_no');
            $table->date('due_date');
            $table->decimal('scheduled_amount', 14, 2);
            $table->string('status', 24)->default('SCHEDULED');
            $table->timestampsTz();

            $table->unique(['installment_plan_id', 'installment_no'], 'uq_installment_plan_no');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE installment_schedule_items ADD CONSTRAINT installment_schedule_items_amount_check CHECK (scheduled_amount > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedule_items');
    }
};
