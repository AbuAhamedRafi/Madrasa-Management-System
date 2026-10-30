<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('source_type', 32)->default('USER_ACTION');
            $table->string('source_reference', 128)->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['enrollment_id', 'occurred_at'], 'idx_enrollment_events_enrollment');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE enrollment_status_events ADD CONSTRAINT enrollment_status_events_source_type_check CHECK (source_type IN ('USER_ACTION', 'BILLING_SEAM', 'PAYMENT_SEAM', 'SYSTEM'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_status_events');
    }
};

