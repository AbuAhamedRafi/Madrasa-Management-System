<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shift_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->string('event_type', 64); // SHIFT_OPENED, PAYMENT_COLLECTED, PAYMENT_REVERSED, SHIFT_CLOSED, VARIANCE_FLAGGED, SHIFT_RECONCILED
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('payload')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cashier_shift_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_events');
    }
};

