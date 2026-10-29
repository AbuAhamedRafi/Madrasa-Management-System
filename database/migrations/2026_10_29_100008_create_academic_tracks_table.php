<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['program_id', 'code'], 'academic_tracks_program_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_tracks');
    }
};

