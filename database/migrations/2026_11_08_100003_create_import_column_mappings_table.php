<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->string('schema_version', 16)->default('1.0');
            $table->string('sheet', 128);
            $table->integer('header_row')->default(1);
            $table->jsonb('mapping_json');
            $table->jsonb('options_json')->nullable();
            $table->integer('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('import_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_column_mappings');
    }
};
