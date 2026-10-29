<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_cohorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->string('label', 64);
            $table->unsignedSmallInteger('intake_year')->index();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 16)->default('DRAFT')->index();
            $table->timestampsTz();

            $table->unique(['program_id', 'label'], 'academic_cohorts_program_label_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_cohorts ADD CONSTRAINT academic_cohorts_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'CLOSED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_cohorts');
    }
};

