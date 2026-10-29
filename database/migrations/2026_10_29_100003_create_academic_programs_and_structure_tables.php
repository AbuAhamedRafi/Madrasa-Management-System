<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('education_level_id')->constrained('education_levels')->restrictOnDelete();
            $table->string('name_bn', 180);
            $table->string('name_en', 180);
            $table->text('description_bn')->nullable();
            $table->text('description_en')->nullable();
            $table->string('scheme', 24);
            $table->unsignedSmallInteger('duration_years');
            $table->string('state', 16)->default('DRAFT')->index();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_programs ADD CONSTRAINT academic_programs_scheme_check CHECK (scheme IN ('CALENDAR_YEAR', 'SESSION_BASED'))");
            DB::statement("ALTER TABLE academic_programs ADD CONSTRAINT academic_programs_state_check CHECK (state IN ('DRAFT', 'ACTIVE', 'INACTIVE'))");
            DB::statement("ALTER TABLE academic_programs ADD CONSTRAINT academic_programs_duration_check CHECK (duration_years BETWEEN 1 AND 12)");
        }

        Schema::create('program_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->unsignedSmallInteger('stage_number');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['program_id', 'code'], 'program_classes_program_code_unique');
            $table->unique(['program_id', 'stage_number'], 'program_classes_program_stage_unique');
        });

        Schema::create('academic_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_bn', 160);
            $table->string('name_en', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['program_id', 'code'], 'academic_tracks_program_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_tracks');
        Schema::dropIfExists('program_classes');
        Schema::dropIfExists('academic_programs');
    }
};

