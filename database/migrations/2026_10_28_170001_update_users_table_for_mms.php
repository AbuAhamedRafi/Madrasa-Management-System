<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_id', 32)->unique()->nullable()->after('id');
            $table->string('name_bn', 150)->nullable()->after('public_id');
            $table->string('name_en', 150)->nullable()->after('name_bn');
            $table->string('username', 50)->unique()->nullable()->after('name_en');
            $table->string('phone', 25)->nullable()->after('email');
            $table->string('status', 20)->default('INVITED')->after('password');
            $table->string('preferred_locale', 10)->default('bn-BD')->after('status');
            $table->timestamp('last_login_at')->nullable()->after('preferred_locale');
            $table->timestamp('disabled_at')->nullable()->after('last_login_at');
            $table->text('disabled_reason')->nullable()->after('disabled_at');

            // Make original name and password nullable (password is null until account activation)
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'public_id',
                'name_bn',
                'name_en',
                'username',
                'phone',
                'status',
                'preferred_locale',
                'last_login_at',
                'disabled_at',
                'disabled_reason',
            ]);
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};

