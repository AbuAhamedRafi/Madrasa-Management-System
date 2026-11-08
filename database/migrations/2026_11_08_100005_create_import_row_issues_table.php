<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_row_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_row_id')->constrained('import_rows')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('severity', 16)->default('ERROR'); // ERROR, WARNING, REVIEW
            $table->string('field', 64)->nullable();
            $table->string('source_column', 128)->nullable();
            $table->string('explanation_key', 64);
            $table->text('message_bn');
            $table->text('message_en');
            $table->jsonb('meta_json')->nullable();
            $table->timestamps();

            $table->index(['import_row_id', 'severity']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_issues');
    }
};
