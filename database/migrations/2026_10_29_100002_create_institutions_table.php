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
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};

