<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 60)->default('Invoice');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('dedupe_key', 160)->unique();
            $table->jsonb('payload_json');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestampsTz();

            $table->index(['event_type', 'published_at'], 'idx_billing_outbox_type_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_outbox');
    }
};

