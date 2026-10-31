<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['invoice_id', 'status'], 'idx_installment_plans_invoice_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE installment_plans ADD CONSTRAINT installment_plans_status_check CHECK (status IN ('ACTIVE', 'REPLACED', 'CANCELLED'))");
            DB::statement("CREATE UNIQUE INDEX uq_active_invoice_installment_plan ON installment_plans (invoice_id) WHERE status = 'ACTIVE'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
