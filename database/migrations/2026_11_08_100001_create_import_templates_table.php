<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->string('name_bn');
            $table->string('name_en');
            $table->string('mode', 32); // CREATE_ONLY, MATCH_UPDATE, ENROLL_ONLY
            $table->string('version', 16)->default('1.0');
            $table->string('locale', 16)->default('bn');
            $table->string('scheme', 32)->default('ALL'); // ALL, YEAR_BASED, SESSION_BASED
            $table->boolean('is_active')->default(true);
            $table->jsonb('schema_json');
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->unique(['code', 'version', 'locale'], 'import_templates_code_version_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};
