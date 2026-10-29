<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->string('name_bn', 255);
            $table->string('name_en', 255);
            $table->string('phone', 32)->index();
            $table->string('alternative_phone', 32)->nullable();
            $table->text('address_bn')->nullable();
            $table->text('address_en')->nullable();
            $table->text('nid_number_ciphertext')->nullable();
            $table->string('occupation', 128)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};

