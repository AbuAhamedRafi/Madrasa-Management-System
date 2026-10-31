<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AcademicFoundationSeeder::class,
        ]);

        // Provision default Super Admin account idempotently
        Artisan::call('mms:admin:bootstrap', [
            '--username' => 'superadmin',
            '--password' => 'SuperAdmin12345!',
            '--name-en' => 'Super Admin',
            '--name-bn' => 'সুপার অ্যাডমিন',
            '--email' => 'superadmin@mms.edu.bd',
        ]);

        // Provision default staff and accounts accounts
        $staffRole = \App\Models\Role::where('key', 'OFFICE_STAFF')->first();
        $accountsRole = \App\Models\Role::where('key', 'ACCOUNTS')->first();

        $staff = \App\Models\User::updateOrCreate(
            ['username' => 'staff'],
            [
                'public_id' => 'usr_staff_default',
                'name_bn' => 'অফিস স্টাফ',
                'name_en' => 'Office Staff',
                'email' => 'staff@mms.edu.bd',
                'password' => bcrypt('StaffPass12345!'),
                'status' => \App\Enums\AccountStatus::ACTIVE,
                'preferred_locale' => 'bn-BD',
            ]
        );
        if ($staffRole) {
            $staff->roles()->sync([$staffRole->id]);
        }

        $accounts = \App\Models\User::updateOrCreate(
            ['username' => 'accounts'],
            [
                'public_id' => 'usr_accounts_default',
                'name_bn' => 'হিসাব শাখা',
                'name_en' => 'Accounts Officer',
                'email' => 'accounts@mms.edu.bd',
                'password' => bcrypt('AccountsPass12345!'),
                'status' => \App\Enums\AccountStatus::ACTIVE,
                'preferred_locale' => 'bn-BD',
            ]
        );
        if ($accountsRole) {
            $accounts->roles()->sync([$accountsRole->id]);
        }

        // Seed comprehensive student and guardian sample foundation
        $this->call([
            StudentFoundationSeeder::class,
            AdmissionsFoundationSeeder::class,
            PromotionsFoundationSeeder::class,
            BillingFoundationSeeder::class,
        ]);
    }
}
