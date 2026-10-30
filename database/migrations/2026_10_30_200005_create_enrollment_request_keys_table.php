<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_request_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->string('state', 32)->default('IN_PROGRESS');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampsTz();

            $table->unique(['actor_id', 'idempotency_key'], 'uq_enrollment_request_key');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE enrollment_request_keys ADD CONSTRAINT enrollment_request_keys_state_check CHECK (state IN ('IN_PROGRESS', 'COMPLETED', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_request_keys');
    }
};

