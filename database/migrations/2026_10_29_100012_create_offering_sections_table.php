<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offering_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_offering_id')->constrained('academic_offerings')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_bn', 120);
            $table->string('name_en', 120);
            $table->integer('capacity');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['academic_offering_id', 'code'], 'offering_sections_offering_code_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE offering_sections ADD CONSTRAINT offering_sections_capacity_check CHECK (capacity > 0 AND capacity <= 5000)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offering_sections');
    }
};

