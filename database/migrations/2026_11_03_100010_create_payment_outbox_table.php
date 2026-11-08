<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 64);
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->jsonb('payload');
            $table->string('status', 32)->default('PENDING'); // PENDING, PROCESSED, FAILED
            $table->integer('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_outbox');
    }
};

