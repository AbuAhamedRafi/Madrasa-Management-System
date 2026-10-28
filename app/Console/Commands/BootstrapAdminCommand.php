<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\SupportedLocale;
use App\Models\Role;
use App\Models\User;
use App\Repositories\IdSequenceRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'mms:admin:bootstrap 
                            {--username=admin : Administrator username}
                            {--password= : Administrator initial password}
                            {--name-en=Administrator : Name in English}
                            {--name-bn=অ্যাডমিনিস্ট্রেটর : Name in Bangla}
                            {--email=admin@mms.edu.bd : Administrator email}';

    protected $description = 'Provision the initial active system administrator account';

    public function handle(IdSequenceRepository $idSequenceRepository): int
    {
        $username = strtolower(trim($this->option('username')));
        $password = $this->option('password') ?: $this->secret('Enter admin password (min 12 chars)');

        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');
            return self::FAILURE;
        }

        $adminRole = Role::where('key', 'ADMIN')->first();
        if (! $adminRole) {
            $this->error('ADMIN role not found. Please run: php artisan db:seed --class=RolePermissionSeeder');
            return self::FAILURE;
        }

        $user = User::where('username', $username)->first();

        if ($user) {
            $this->info("User [{$username}] already exists. Ensuring ADMIN role and ACTIVE status...");
            $user->update([
                'password' => Hash::make($password),
                'status' => AccountStatus::ACTIVE,
            ]);
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        } else {
            $publicId = $idSequenceRepository->nextPublicId('user_public_id', 'usr_', 3);

            $user = User::create([
                'public_id' => $publicId,
                'name_bn' => $this->option('name-bn'),
                'name_en' => $this->option('name-en'),
                'username' => $username,
                'email' => strtolower(trim($this->option('email'))),
                'password' => Hash::make($password),
                'status' => AccountStatus::ACTIVE,
                'preferred_locale' => SupportedLocale::default(),
            ]);

            $user->roles()->attach($adminRole->id, ['created_at' => now()]);
            $this->info("Created administrator [{$publicId}] with username: {$username}");
        }

        $this->info('Administrator bootstrap completed successfully.');
        return self::SUCCESS;
    }
}

