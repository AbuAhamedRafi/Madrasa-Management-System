<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('scope', 64);
            $table->string('request_hash', 64);
            $table->string('outcome_ref', 128)->nullable();
            $table->string('state', 32)->default('IN_PROGRESS');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestampTz('expires_at')->nullable();

            $table->index(['scope', 'key'], 'idx_promotion_idempotency_scope_key');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE promotion_idempotency_keys ADD CONSTRAINT promotion_idempotency_keys_state_check CHECK (state IN ('IN_PROGRESS', 'COMPLETED', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_idempotency_keys');
    }
};

