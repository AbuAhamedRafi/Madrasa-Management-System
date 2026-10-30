<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 64);
            $table->string('aggregate_type', 64)->default('Enrollment');
            $table->unsignedBigInteger('aggregate_id');
            $table->jsonb('payload_jsonb');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['published_at', 'created_at'], 'idx_enrollment_outbox_pub_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_outbox');
    }
};
