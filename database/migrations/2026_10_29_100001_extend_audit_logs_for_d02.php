<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('entity_type', 64)->nullable()->after('subject_user_id');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            $table->text('reason')->nullable()->after('after_metadata');

            $table->index(['entity_type', 'entity_id'], 'audit_logs_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_entity_idx');
            $table->dropColumn(['entity_type', 'entity_id', 'reason']);
        });
    }
};

