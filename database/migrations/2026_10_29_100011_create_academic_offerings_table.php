<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_offerings');
    }
};

