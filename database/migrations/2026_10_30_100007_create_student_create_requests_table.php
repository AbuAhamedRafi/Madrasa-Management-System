<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_create_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->jsonb('response_payload')->nullable();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['actor_id', 'idempotency_key'], 'student_create_requests_actor_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_create_requests');
    }
};

