<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_run_id')->constrained('billing_runs')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->string('source_key', 160);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('status', 24)->default('PENDING');
            $table->string('reason_code', 64)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['billing_run_id', 'enrollment_id', 'source_key'], 'uq_billing_run_enrollment_source');
            $table->index(['billing_run_id', 'status'], 'idx_billing_run_items_run_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE billing_run_items ADD CONSTRAINT billing_run_items_status_check CHECK (status IN ('PENDING', 'PROCESSING', 'SUCCEEDED', 'SKIPPED', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_run_items');
    }
};

