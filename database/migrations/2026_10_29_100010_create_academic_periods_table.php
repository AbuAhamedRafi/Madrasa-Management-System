<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_periods');
    }
};

