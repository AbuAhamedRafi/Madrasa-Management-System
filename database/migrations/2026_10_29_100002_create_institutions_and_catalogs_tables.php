<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_bn', 180);
            $table->string('name_en', 180);
            $table->text('address_bn')->nullable();
            $table->text('address_en')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('default_locale', 10)->default('bn-BD');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->timestampsTz();
        });

        Schema::create('education_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

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

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->text('description_bn')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('education_levels');
        Schema::dropIfExists('education_authorities');
        Schema::dropIfExists('institutions');
    }
};

