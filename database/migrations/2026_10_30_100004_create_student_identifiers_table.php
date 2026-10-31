<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('namespace', 64);
            $table->string('value_normalized', 128);
            $table->text('value_raw');
            $table->string('issuer', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['namespace', 'value_normalized'], 'student_identifiers_ns_val_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_identifiers');
    }
};

