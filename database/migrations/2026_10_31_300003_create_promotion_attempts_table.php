<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_item_id')->constrained('promotion_items')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key', 128)->unique();
            $table->string('request_hash', 64);
            $table->string('status', 32);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->jsonb('result_json')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->index(['promotion_item_id', 'attempt_number'], 'idx_promotion_attempts_item_num');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE promotion_attempts ADD CONSTRAINT promotion_attempts_status_check CHECK (status IN ('STARTED', 'SUCCEEDED', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_attempts');
    }
};

