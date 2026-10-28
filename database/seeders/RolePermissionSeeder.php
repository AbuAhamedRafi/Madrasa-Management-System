<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Roles catalog (Admin, Office Staff, Accounts)
        $rolesData = [
            [
                'key' => 'ADMIN',
                'name_bn' => 'অ্যাডমিন',
                'name_en' => 'Admin',
                'description_bn' => 'সিস্টেম ও ব্যবহারকারী ব্যবস্থাপনা সহ পূর্ণ প্রশাসনিক সুবিধা।',
                'description_en' => 'Full administrative access and user management capability.',
                'is_system' => true,
            ],
            [
                'key' => 'OFFICE_STAFF',
                'name_bn' => 'অফিস স্টাফ',
                'name_en' => 'Office Staff',
                'description_bn' => 'ভর্তি, শিক্ষার্থী ব্যবস্থাপনা ও স্লিপ ইস্যুর দায়িত্ব।',
                'description_en' => 'Handles student profiles, admissions, and payment slip issuance.',
                'is_system' => true,
            ],
            [
                'key' => 'ACCOUNTS',
                'name_bn' => 'হিসাব শাখা',
                'name_en' => 'Accounts',
                'description_bn' => 'নগদ জমা গ্রহণ, রসিদ প্রদান ও আর্থিক প্রতিবেদন।',
                'description_en' => 'Handles cash payment collection, receipts, and financial reports.',
                'is_system' => true,
            ],
        ];

        $roles = [];
        foreach ($rolesData as $data) {
            $roles[$data['key']] = Role::updateOrCreate(['key' => $data['key']], $data);
        }

        // 2. Permission catalog (D01-D12)
        $permissionsData = [
            // D01: User and Identity
            ['key' => 'profile.read', 'module' => 'D01', 'label_bn' => 'প্রোফাইল দেখা', 'label_en' => 'View own profile', 'display_order' => 1],
            ['key' => 'profile.update', 'module' => 'D01', 'label_bn' => 'প্রোফাইল আপডেট', 'label_en' => 'Update own profile', 'display_order' => 2],
            ['key' => 'password.change', 'module' => 'D01', 'label_bn' => 'পাসওয়ার্ড পরিবর্তন', 'label_en' => 'Change password', 'display_order' => 3],
            ['key' => 'users.read', 'module' => 'D01', 'label_bn' => 'ব্যবহারকারী তালিকা দেখা', 'label_en' => 'View users', 'display_order' => 4],
            ['key' => 'users.create', 'module' => 'D01', 'label_bn' => 'নতুন ব্যবহারকারী তৈরি', 'label_en' => 'Create users', 'display_order' => 5],
            ['key' => 'users.update', 'module' => 'D01', 'label_bn' => 'ব্যবহারকারী তথ্য সম্পাদনা', 'label_en' => 'Update users', 'display_order' => 6],
            ['key' => 'users.disable', 'module' => 'D01', 'label_bn' => 'ব্যবহারকারী নিষ্ক্রিয়করণ', 'label_en' => 'Disable users', 'display_order' => 7],
            ['key' => 'roles.read', 'module' => 'D01', 'label_bn' => 'ভূমিকা ও অনুমতি দেখা', 'label_en' => 'View roles and permissions', 'display_order' => 8],
            ['key' => 'users.assign_role', 'module' => 'D01', 'label_bn' => 'ভূমিকা বরাদ্দকরণ', 'label_en' => 'Assign roles', 'display_order' => 9],
            ['key' => 'audits.read', 'module' => 'D01', 'label_bn' => 'অডিট লগ পরিদর্শন', 'label_en' => 'View audit logs', 'display_order' => 10],

            // D02: Academics
            ['key' => 'academics.manage', 'module' => 'D02', 'label_bn' => 'একাডেমিক কনফিগারেশন', 'label_en' => 'Academic configuration', 'display_order' => 11],

            // D03: Students
            ['key' => 'students.create', 'module' => 'D03', 'label_bn' => 'শিক্ষার্থী নিবন্ধন তৈরি', 'label_en' => 'Create student', 'display_order' => 12],
            ['key' => 'students.update', 'module' => 'D03', 'label_bn' => 'শিক্ষার্থী তথ্য পরিবর্তন', 'label_en' => 'Update student', 'display_order' => 13],

            // D04-D05: Admissions & Promotions
            ['key' => 'admissions.manage', 'module' => 'D04', 'label_bn' => 'ভর্তি কার্যক্রম পরিচালনা', 'label_en' => 'Manage admissions', 'display_order' => 14],
            ['key' => 'promotions.manage', 'module' => 'D05', 'label_bn' => 'প্রমোশন পরিচালনা', 'label_en' => 'Manage promotions', 'display_order' => 15],

            // D07: Payment Slips
            ['key' => 'payment_slips.issue', 'module' => 'D07', 'label_bn' => 'পেমেন্ট স্লিপ ইস্যু', 'label_en' => 'Issue payment slip', 'display_order' => 16],
            ['key' => 'payment_slips.read', 'module' => 'D07', 'label_bn' => 'পেমেন্ট স্লিপ পরিদর্শন', 'label_en' => 'Read payment slips', 'display_order' => 17],

            // D08: Cash Payments
            ['key' => 'payments.post', 'module' => 'D08', 'label_bn' => 'নগদ জমা গ্রহণ (ক্যাশিয়ার)', 'label_en' => 'Post cash payments', 'display_order' => 18],

            // D11: Reports & Finance
            ['key' => 'finance.reports.read', 'module' => 'D11', 'label_bn' => 'আর্থিক প্রতিবেদন দেখা', 'label_en' => 'View financial reports', 'display_order' => 19],
        ];

        $permissions = [];
        foreach ($permissionsData as $data) {
            $permissions[$data['key']] = Permission::updateOrCreate(['key' => $data['key']], $data);
        }

        // 3. Bind permissions to roles (Idempotent matrix per SRS v3.0 Section 2)
        // ADMIN grants
        $adminPermissions = [
            'profile.read', 'profile.update', 'password.change',
            'users.read', 'users.create', 'users.update', 'users.disable',
            'roles.read', 'users.assign_role', 'audits.read',
            'academics.manage', 'students.create', 'students.update',
            'admissions.manage', 'promotions.manage',
            'payment_slips.issue', 'payment_slips.read',
            'finance.reports.read',
        ];

        foreach ($adminPermissions as $permKey) {
            $roles['ADMIN']->permissions()->syncWithoutDetaching([
                $permissions[$permKey]->id => ['access_level' => 'ALLOWED']
            ]);
        }

        // OFFICE_STAFF grants
        $staffPermissions = [
            'profile.read', 'profile.update', 'password.change',
            'students.create', 'students.update',
            'admissions.manage', 'promotions.manage',
            'payment_slips.issue',
        ];

        foreach ($staffPermissions as $permKey) {
            $roles['OFFICE_STAFF']->permissions()->syncWithoutDetaching([
                $permissions[$permKey]->id => ['access_level' => 'ALLOWED']
            ]);
        }
        $roles['OFFICE_STAFF']->permissions()->syncWithoutDetaching([
            $permissions['payment_slips.read']->id => ['access_level' => 'RESTRICTED']
        ]);

        // ACCOUNTS grants
        $accountsPermissions = [
            'profile.read', 'profile.update', 'password.change',
            'payments.post', 'finance.reports.read',
        ];

        foreach ($accountsPermissions as $permKey) {
            $roles['ACCOUNTS']->permissions()->syncWithoutDetaching([
                $permissions[$permKey]->id => ['access_level' => 'ALLOWED']
            ]);
        }
        $roles['ACCOUNTS']->permissions()->syncWithoutDetaching([
            $permissions['payment_slips.read']->id => ['access_level' => 'VIEW_ONLY'],
            $permissions['audits.read']->id => ['access_level' => 'OWN_ONLY'],
        ]);
    }
}

