<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authority_id')->constrained('education_authorities')->restrictOnDelete();
            $table->string('code', 24);
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['authority_id', 'code'], 'education_levels_authority_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_levels');
    }
};

