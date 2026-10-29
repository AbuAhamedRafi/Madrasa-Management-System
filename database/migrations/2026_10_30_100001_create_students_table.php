<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_id', 24)->unique();
            $table->string('name_bn', 255);
            $table->string('name_en', 255);
            $table->date('date_of_birth');
            $table->string('gender', 16);
            $table->date('admission_date');
            $table->string('status', 24)->default('REGISTERED')->index();
            $table->text('birth_registration_ciphertext')->nullable();
            $table->string('birth_registration_hash', 64)->nullable()->index();
            $table->text('present_address_bn')->nullable();
            $table->text('present_address_en')->nullable();
            $table->text('permanent_address_bn')->nullable();
            $table->text('permanent_address_en')->nullable();
            $table->string('profile_photo_path', 512)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE students ADD CONSTRAINT students_gender_check CHECK (gender IN ('MALE', 'FEMALE'))");
            DB::statement("ALTER TABLE students ADD CONSTRAINT students_status_check CHECK (status IN ('REGISTERED', 'ACTIVE', 'INACTIVE', 'WITHDRAWN', 'TRANSFERRED', 'GRADUATED', 'ARCHIVED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

