<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained('academic_periods')->restrictOnDelete();
            $table->foreignId('academic_offering_id')->nullable()->constrained('academic_offerings')->restrictOnDelete();
            $table->foreignId('program_class_id')->nullable()->constrained('program_classes')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['program_id', 'academic_period_id', 'status'], 'idx_fee_structures_scope_status');
            $table->index(['academic_offering_id', 'status'], 'idx_fee_structures_offering_status');
            $table->index(['effective_from', 'effective_to'], 'idx_fee_structures_effective_dates');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_structures ADD CONSTRAINT fee_structures_status_check CHECK (status IN ('DRAFT', 'PUBLISHED', 'ARCHIVED'))");
            DB::statement("ALTER TABLE fee_structures ADD CONSTRAINT fee_structures_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};
