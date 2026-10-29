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
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_programs');
    }
};

