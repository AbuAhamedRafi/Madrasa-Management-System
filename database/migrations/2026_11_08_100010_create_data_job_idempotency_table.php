<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_job_idempotency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->string('result_type', 64)->nullable();
            $table->bigInteger('result_id')->nullable();
            $table->integer('response_code')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->string('state', 32)->default('IN_PROGRESS'); // IN_PROGRESS, COMPLETED, FAILED
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['actor_id', 'action', 'idempotency_key'], 'data_job_idempotency_actor_action_key_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_job_idempotency');
    }
};
