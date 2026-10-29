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

        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained('academic_cohorts')->restrictOnDelete();
            $table->string('label', 120);
            $table->unsignedSmallInteger('calendar_year')->nullable();
            $table->unsignedSmallInteger('study_year_number')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('state', 16)->default('DRAFT')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_state_check CHECK (state IN ('DRAFT', 'OPEN', 'CLOSED'))");
            DB::statement("ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_dates_check CHECK (start_date <= end_date)");
            DB::statement("ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_scheme_fields_check CHECK ((cohort_id IS NULL AND calendar_year IS NOT NULL AND study_year_number IS NULL) OR (cohort_id IS NOT NULL AND calendar_year IS NULL AND study_year_number IS NOT NULL))");
            DB::statement("CREATE UNIQUE INDEX academic_periods_calendar_unique_idx ON academic_periods (program_id, calendar_year) WHERE cohort_id IS NULL");
            DB::statement("CREATE UNIQUE INDEX academic_periods_session_unique_idx ON academic_periods (cohort_id, study_year_number) WHERE cohort_id IS NOT NULL");
        }

        Schema::create('academic_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_period_id')->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('program_class_id')->constrained('program_classes')->restrictOnDelete();
            $table->foreignId('academic_track_id')->nullable()->constrained('academic_tracks')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX academic_offerings_identity_unique_idx ON academic_offerings (academic_period_id, program_class_id, COALESCE(academic_track_id, 0))");
        }

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
        Schema::dropIfExists('academic_offerings');
        Schema::dropIfExists('academic_periods');
        Schema::dropIfExists('academic_cohorts');
    }
};

