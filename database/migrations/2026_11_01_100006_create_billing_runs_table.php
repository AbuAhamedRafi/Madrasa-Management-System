<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_number', 32)->unique();
            $table->string('type', 32)->default('MONTHLY_TUITION');
            $table->foreignId('academic_period_id')->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('academic_offering_id')->nullable()->constrained('academic_offerings')->restrictOnDelete();
            $table->date('billing_month')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('preview_hash', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['academic_period_id', 'type', 'status'], 'idx_billing_runs_period_type_status');
            $table->index('billing_month', 'idx_billing_runs_billing_month');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE billing_runs ADD CONSTRAINT billing_runs_type_check CHECK (type IN ('ENROLLMENT', 'MONTHLY_TUITION', 'EXAM', 'OTHER'))");
            DB::statement("ALTER TABLE billing_runs ADD CONSTRAINT billing_runs_status_check CHECK (status IN ('DRAFT', 'PREVIEWED', 'QUEUED', 'RUNNING', 'COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};

