<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_duplicate_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('proposed_payload_hash', 64)->index();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->jsonb('candidate_matches')->default('[]');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('PENDING')->index();
            $table->text('decision_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_duplicate_reviews ADD CONSTRAINT student_duplicate_reviews_status_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'EXPIRED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_duplicate_reviews');
    }
};

