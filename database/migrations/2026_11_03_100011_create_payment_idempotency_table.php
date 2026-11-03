<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->string('operation', 64);
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->integer('response_code');
            $table->jsonb('response_body');
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['idempotency_key', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_idempotency');
    }
};

