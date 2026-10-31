<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_batch_id')->constrained('promotion_batches')->cascadeOnDelete();
            $table->foreignId('promotion_item_id')->nullable()->constrained('promotion_items')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->index(['promotion_batch_id', 'occurred_at'], 'idx_promotion_events_batch_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_events');
    }
};

