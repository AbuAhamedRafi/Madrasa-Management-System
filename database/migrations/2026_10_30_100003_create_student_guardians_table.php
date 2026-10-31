<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->restrictOnDelete();
            $table->string('relation', 32);
            $table->boolean('is_primary')->default(false);
            $table->boolean('sms_consent')->default(false);
            $table->timestampTz('consent_at')->nullable();
            $table->string('consent_source', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['student_id', 'guardian_id'], 'student_guardians_student_guardian_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_guardians ADD CONSTRAINT student_guardians_relation_check CHECK (relation IN ('FATHER', 'MOTHER', 'LEGAL_GUARDIAN', 'OTHER'))");
            DB::statement("CREATE UNIQUE INDEX student_guardians_single_primary_idx ON student_guardians (student_id) WHERE is_primary = true");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
    }
};

