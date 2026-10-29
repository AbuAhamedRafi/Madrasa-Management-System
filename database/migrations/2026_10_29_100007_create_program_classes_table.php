<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->unsignedSmallInteger('stage_number');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['program_id', 'code'], 'program_classes_program_code_unique');
            $table->unique(['program_id', 'stage_number'], 'program_classes_program_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_classes');
    }
};

