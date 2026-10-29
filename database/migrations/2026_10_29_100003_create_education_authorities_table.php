<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_authorities');
    }
};

