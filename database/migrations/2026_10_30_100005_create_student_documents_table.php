<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('file_name', 255);
            $table->string('storage_key', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('verification_status', 24)->default('PENDING')->index();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_reason')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_type_check CHECK (type IN ('BIRTH_CERTIFICATE', 'PREVIOUS_TRANSCRIPT', 'NID_GUARDIAN', 'MEDICAL', 'OTHER'))");
            DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_verification_status_check CHECK (verification_status IN ('PENDING', 'VERIFIED', 'REJECTED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};

