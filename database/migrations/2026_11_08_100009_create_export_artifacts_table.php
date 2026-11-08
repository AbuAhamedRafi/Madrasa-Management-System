<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('export_job_id')->constrained('export_jobs')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('sha256', 64);
            $table->string('object_key', 512);
            $table->bigInteger('byte_size')->default(0);
            $table->string('mime', 128)->default('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $table->timestamp('expire_at');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index('export_job_id');
            $table->index('expire_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_artifacts');
    }
};
