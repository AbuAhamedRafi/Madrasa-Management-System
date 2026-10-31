<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_event_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 120)->unique();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 60)->default('Enrollment');
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->string('payload_hash', 64);
            $table->jsonb('payload_json')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['event_type', 'aggregate_id'], 'idx_billing_inbox_type_aggregate');
            $table->index('status', 'idx_billing_inbox_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_event_inbox');
    }
};
