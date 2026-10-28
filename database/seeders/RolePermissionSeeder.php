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
            ['key' => 'profile.read', 'module' => 'D01_Identity', 'label_bn' => 'প্রোফাইল দেখা', 'label_en' => 'View own profile', 'display_order' => 1],
            ['key' => 'profile.update', 'module' => 'D01_Identity', 'label_bn' => 'প্রোফাইল আপডেট', 'label_en' => 'Update own profile', 'display_order' => 2],
            ['key' => 'password.change', 'module' => 'D01_Identity', 'label_bn' => 'পাসওয়ার্ড পরিবর্তন', 'label_en' => 'Change password', 'display_order' => 3],
            ['key' => 'users.read', 'module' => 'D01_Users', 'label_bn' => 'ব্যবহারকারী তালিকা দেখা', 'label_en' => 'View users list', 'display_order' => 4],
            ['key' => 'users.create', 'module' => 'D01_Users', 'label_bn' => 'নতুন ব্যবহারকারী তৈরি', 'label_en' => 'Create users', 'display_order' => 5],
            ['key' => 'users.update', 'module' => 'D01_Users', 'label_bn' => 'ব্যবহারকারী তথ্য সম্পাদনা', 'label_en' => 'Update users', 'display_order' => 6],
            ['key' => 'users.disable', 'module' => 'D01_Users', 'label_bn' => 'ব্যবহারকারী নিষ্ক্রিয়করণ', 'label_en' => 'Disable / enable users', 'display_order' => 7],
            ['key' => 'users.assign_role', 'module' => 'D01_Users', 'label_bn' => 'ভূমিকা ও পারমিশন বরাদ্দকরণ', 'label_en' => 'Assign roles & custom permissions', 'display_order' => 8],
            ['key' => 'roles.read', 'module' => 'D01_Roles', 'label_bn' => 'ভূমিকা ও অনুমতি দেখা', 'label_en' => 'View roles and permissions', 'display_order' => 9],
            ['key' => 'roles.manage', 'module' => 'D01_Roles', 'label_bn' => 'ভূমিকা তৈরি ও সম্পাদনা', 'label_en' => 'Create and customize roles', 'display_order' => 10],
            ['key' => 'permissions.manage', 'module' => 'D01_Roles', 'label_bn' => 'কাস্টম পারমিশন তৈরি', 'label_en' => 'Create custom permission flags', 'display_order' => 11],
            ['key' => 'audits.read', 'module' => 'D01_Audit', 'label_bn' => 'অডিট লগ পরিদর্শন', 'label_en' => 'View system audit logs', 'display_order' => 12],

            // D02: Academics
            ['key' => 'academics.programs.read', 'module' => 'D02_Academics', 'label_bn' => 'একাডেমিক বিভাগ ও কারিকুলাম দেখা', 'label_en' => 'View academic programs', 'display_order' => 13],
            ['key' => 'academics.programs.manage', 'module' => 'D02_Academics', 'label_bn' => 'একাডেমিক বিভাগ পরিচালনা', 'label_en' => 'Manage academic programs', 'display_order' => 14],
            ['key' => 'academics.sessions.manage', 'module' => 'D02_Academics', 'label_bn' => 'শিক্ষাবর্ষ সেশন কনফিগারেশন', 'label_en' => 'Configure academic sessions', 'display_order' => 15],
            ['key' => 'academics.classes.manage', 'module' => 'D02_Academics', 'label_bn' => 'শ্রেণী ও সেকশন ব্যবস্থাপনা', 'label_en' => 'Manage classes & sections', 'display_order' => 16],
            ['key' => 'academics.subjects.manage', 'module' => 'D02_Academics', 'label_bn' => 'বিষয় ও সিলেবাস নির্ধারণ', 'label_en' => 'Manage subjects & syllabi', 'display_order' => 17],
            ['key' => 'academics.manage', 'module' => 'D02_Academics', 'label_bn' => 'সার্বিক একাডেমিক ব্যবস্থাপনা', 'label_en' => 'Master academic management', 'display_order' => 18],

            // D03: Students
            ['key' => 'students.read', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থী তালিকা দেখা', 'label_en' => 'View student directory', 'display_order' => 19],
            ['key' => 'students.create', 'module' => 'D03_Students', 'label_bn' => 'নতুন শিক্ষার্থী নিবন্ধন', 'label_en' => 'Create student profiles', 'display_order' => 20],
            ['key' => 'students.update', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থীর তথ্য পরিবর্তন', 'label_en' => 'Update student records', 'display_order' => 21],
            ['key' => 'students.delete', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থী রেকর্ড মুছা', 'label_en' => 'Archive or delete students', 'display_order' => 22],
            ['key' => 'students.export', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থী তথ্য এক্সপোর্ট', 'label_en' => 'Export student registers', 'display_order' => 23],

            // D04-D05: Admissions & Promotions
            ['key' => 'admissions.read', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি আবেদন পরিদর্শন', 'label_en' => 'View admission applications', 'display_order' => 24],
            ['key' => 'admissions.create', 'module' => 'D04_Admissions', 'label_bn' => 'নতুন ভর্তি আবেদন জমা', 'label_en' => 'Submit admission applications', 'display_order' => 25],
            ['key' => 'admissions.process', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি যাচাই ও প্রক্রিয়াকরণ', 'label_en' => 'Process & verify admissions', 'display_order' => 26],
            ['key' => 'admissions.approve', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি অনুমোদন ও রোল নির্ধারণ', 'label_en' => 'Final admission approval', 'display_order' => 27],
            ['key' => 'admissions.manage', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি কার্যক্রম পরিচালনা', 'label_en' => 'Master admission management', 'display_order' => 28],
            ['key' => 'promotions.read', 'module' => 'D05_Promotions', 'label_bn' => 'প্রমোশন তালিকা পরিদর্শন', 'label_en' => 'View promotion eligibility', 'display_order' => 29],
            ['key' => 'promotions.manage', 'module' => 'D05_Promotions', 'label_bn' => 'প্রমোশন রুলস নির্ধারণ', 'label_en' => 'Configure promotion rules', 'display_order' => 30],
            ['key' => 'promotions.execute', 'module' => 'D05_Promotions', 'label_bn' => 'বার্ষিক প্রমোশন কার্যকরকরণ', 'label_en' => 'Execute annual promotions', 'display_order' => 31],

            // D06-D07: Fees & Payment Slips
            ['key' => 'fees.catalog.read', 'module' => 'D06_Fees', 'label_bn' => 'ফি কাঠামো ও তালিকা দেখা', 'label_en' => 'View fee structures', 'display_order' => 32],
            ['key' => 'fees.catalog.manage', 'module' => 'D06_Fees', 'label_bn' => 'ফি রেট ও খাত নির্ধারণ', 'label_en' => 'Manage fee heads & rates', 'display_order' => 33],
            ['key' => 'fees.discounts.manage', 'module' => 'D06_Fees', 'label_bn' => 'বৃত্তি ও বিশেষ ছাড় নির্ধারণ', 'label_en' => 'Manage fee waivers & discounts', 'display_order' => 34],
            ['key' => 'payment_slips.read', 'module' => 'D07_PaymentSlips', 'label_bn' => 'পেমেন্ট স্লিপ পরিদর্শন', 'label_en' => 'Read payment slips', 'display_order' => 35],
            ['key' => 'payment_slips.issue', 'module' => 'D07_PaymentSlips', 'label_bn' => 'পেমেন্ট স্লিপ ইস্যু', 'label_en' => 'Issue payment slips', 'display_order' => 36],
            ['key' => 'payment_slips.cancel', 'module' => 'D07_PaymentSlips', 'label_bn' => 'ভুল স্লিপ বাতিলকরণ', 'label_en' => 'Void or cancel payment slips', 'display_order' => 37],

            // D08-D09: Cash Payments & Expenses
            ['key' => 'payments.read', 'module' => 'D08_CashPayments', 'label_bn' => 'জমা রসিদ খতিয়ান দেখা', 'label_en' => 'View cash receipts register', 'display_order' => 38],
            ['key' => 'payments.post', 'module' => 'D08_CashPayments', 'label_bn' => 'নগদ জমা গ্রহণ (ক্যাশ কাউন্টার)', 'label_en' => 'Accept & post cash payments', 'display_order' => 39],
            ['key' => 'payments.refund', 'module' => 'D08_CashPayments', 'label_bn' => 'টাকা ফেরত প্রদান অনুমোদন', 'label_en' => 'Authorize cash refunds', 'display_order' => 40],
            ['key' => 'cash_register.close', 'module' => 'D08_CashPayments', 'label_bn' => 'দৈনিক ক্যাশ ক্লোজিং ও ব্যালেন্স', 'label_en' => 'Daily counter cash closing', 'display_order' => 41],
            ['key' => 'expenses.read', 'module' => 'D09_Expenses', 'label_bn' => 'দৈনিক খরচ ও ভাউচার দেখা', 'label_en' => 'View daily expense vouchers', 'display_order' => 42],
            ['key' => 'expenses.create', 'module' => 'D09_Expenses', 'label_bn' => 'নতুন খরচ ভাউচার তৈরি', 'label_en' => 'Create expense voucher', 'display_order' => 43],
            ['key' => 'expenses.approve', 'module' => 'D09_Expenses', 'label_bn' => 'খরচ অনুমোদন (মুহতামিম/কমিটি)', 'label_en' => 'Approve expense payments', 'display_order' => 44],

            // D11: Reports & Analytics
            ['key' => 'finance.reports.read', 'module' => 'D11_Reports', 'label_bn' => 'আর্থিক হিসাব প্রতিবেদন দেখা', 'label_en' => 'View financial statement reports', 'display_order' => 45],
            ['key' => 'reports.academic.read', 'module' => 'D11_Reports', 'label_bn' => 'একাডেমিক অগ্রগতি প্রতিবেদন', 'label_en' => 'View academic reports', 'display_order' => 46],
            ['key' => 'reports.financial.read', 'module' => 'D11_Reports', 'label_bn' => 'আদায় ও ব্যয়ের সামারি রিপোর্ট', 'label_en' => 'View collection & expense summaries', 'display_order' => 47],
            ['key' => 'reports.audit.export', 'module' => 'D11_Reports', 'label_bn' => 'অডিট ডাটা এক্সপোর্ট', 'label_en' => 'Export system audit reports', 'display_order' => 48],

            // D12: System Settings
            ['key' => 'settings.read', 'module' => 'D12_Settings', 'label_bn' => 'প্রতিষ্ঠান কনফিগারেশন দেখা', 'label_en' => 'View institutional settings', 'display_order' => 49],
            ['key' => 'settings.update', 'module' => 'D12_Settings', 'label_bn' => 'প্রতিষ্ঠান সেটিংস পরিবর্তন', 'label_en' => 'Update institutional settings', 'display_order' => 50],
            ['key' => 'system.backup.manage', 'module' => 'D12_Settings', 'label_bn' => 'ডাটাবেজ ব্যাকআপ ও রিস্টোর', 'label_en' => 'Manage database backup archives', 'display_order' => 51],
        ];

        $permissions = [];
        foreach ($permissionsData as $data) {
            $permissions[$data['key']] = Permission::updateOrCreate(['key' => $data['key']], $data);
        }

        // 3. Bind permissions to roles (Idempotent matrix per SRS v3.0 Section 2)
        // ADMIN gets ALL permissions
        $allPermissionIds = array_map(fn($p) => $p->id, $permissions);
        $adminSync = [];
        foreach ($allPermissionIds as $pId) {
            $adminSync[$pId] = ['access_level' => 'ALLOWED'];
        }
        $roles['ADMIN']->permissions()->sync($adminSync);

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

