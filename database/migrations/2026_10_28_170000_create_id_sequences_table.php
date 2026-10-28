<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('id_sequences', function (Blueprint $table) {
            $table->string('scope', 50)->primary();
            $table->string('prefix', 16);
            $table->unsignedBigInteger('current_value')->default(0);
            $table->unsignedSmallInteger('padding')->default(3);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
    }
};

