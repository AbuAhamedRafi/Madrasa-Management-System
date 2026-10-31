<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_bn', 150);
            $table->string('name_en', 150);
            $table->string('billing_type', 32)->default('RECURRING');
            $table->text('description_bn')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('billing_type', 'idx_fee_categories_billing_type');
            $table->index('is_active', 'idx_fee_categories_is_active');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_categories ADD CONSTRAINT fee_categories_billing_type_check CHECK (billing_type IN ('RECURRING', 'ONE_TIME', 'ADMISSION', 'EXAM', 'OTHER'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_categories');
    }
};
