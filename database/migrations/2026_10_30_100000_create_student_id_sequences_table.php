<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_id_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 32);
            $table->string('prefix', 16);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestampsTz();

            $table->unique(['namespace', 'prefix'], 'student_id_sequences_ns_prefix_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_id_sequences');
    }
};

