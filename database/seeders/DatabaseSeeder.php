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
    }
}
