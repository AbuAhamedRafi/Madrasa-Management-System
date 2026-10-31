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

            // D02: Institution & Academics (Canonical D02 contract keys + granular flags)
            ['key' => 'institution.read', 'module' => 'D02_Institution', 'label_bn' => 'প্রতিষ্ঠানের তথ্য দেখা', 'label_en' => 'Read institution profile & branding', 'display_order' => 13],
            ['key' => 'institution.update', 'module' => 'D02_Institution', 'label_bn' => 'প্রতিষ্ঠানের তথ্য ও লোগো আপডেট', 'label_en' => 'Update institution profile & logo', 'display_order' => 14],
            ['key' => 'academics.read', 'module' => 'D02_Academics', 'label_bn' => 'একাডেমিক কাঠামো ও শিক্ষাকাল দেখা', 'label_en' => 'Read academic structure, periods & offerings', 'display_order' => 15],
            ['key' => 'academics.manage_structure', 'module' => 'D02_Academics', 'label_bn' => 'কর্তৃপক্ষ, বিভাগ, প্রোগ্রাম ও শ্রেণি পরিচালনা', 'label_en' => 'Manage authorities, levels, departments, programs & classes', 'display_order' => 16],
            ['key' => 'academics.manage_cohorts', 'module' => 'D02_Academics', 'label_bn' => 'ভর্তি সেশন (কোহর্ট) পরিচালনা', 'label_en' => 'Manage session-based admission cohorts', 'display_order' => 17],
            ['key' => 'academics.manage_periods', 'module' => 'D02_Academics', 'label_bn' => 'একাডেমিক সময়কাল তৈরি ও সম্পাদনা', 'label_en' => 'Create and edit academic periods', 'display_order' => 18],
            ['key' => 'academics.manage_offerings', 'module' => 'D02_Academics', 'label_bn' => 'শ্রেণি অফারিং ও শাখা ধারণক্ষমতা পরিচালনা', 'label_en' => 'Manage period offerings and section capacities', 'display_order' => 19],
            ['key' => 'academics.close_periods', 'module' => 'D02_Academics', 'label_bn' => 'শিক্ষাকাল চালু, বন্ধ ও পুনরায় চালু নিয়ন্ত্রণ', 'label_en' => 'Open, close and reopen academic periods', 'display_order' => 20],
            ['key' => 'academics.programs.read', 'module' => 'D02_Academics', 'label_bn' => 'একাডেমিক বিভাগ ও কারিকুলাম দেখা', 'label_en' => 'View academic programs', 'display_order' => 21],
            ['key' => 'academics.programs.manage', 'module' => 'D02_Academics', 'label_bn' => 'একাডেমিক বিভাগ পরিচালনা', 'label_en' => 'Manage academic programs', 'display_order' => 22],
            ['key' => 'academics.sessions.manage', 'module' => 'D02_Academics', 'label_bn' => 'শিক্ষাবর্ষ সেশন কনফিগারেশন', 'label_en' => 'Configure academic sessions', 'display_order' => 23],
            ['key' => 'academics.classes.manage', 'module' => 'D02_Academics', 'label_bn' => 'শ্রেণী ও সেকশন ব্যবস্থাপনা', 'label_en' => 'Manage classes & sections', 'display_order' => 24],
            ['key' => 'academics.subjects.manage', 'module' => 'D02_Academics', 'label_bn' => 'বিষয় ও সিলেবাস নির্ধারণ', 'label_en' => 'Manage subjects & syllabi', 'display_order' => 25],
            ['key' => 'academics.manage', 'module' => 'D02_Academics', 'label_bn' => 'সার্বিক একাডেমিক ব্যবস্থাপনা', 'label_en' => 'Master academic management', 'display_order' => 26],

            // D03: Students & Guardians
            ['key' => 'students.read', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থী তালিকা ও প্রোফাইল দেখা', 'label_en' => 'View student directory & profiles', 'display_order' => 27],
            ['key' => 'students.create', 'module' => 'D03_Students', 'label_bn' => 'নতুন শিক্ষার্থী ভর্তি / নিবন্ধন', 'label_en' => 'Register new students', 'display_order' => 28],
            ['key' => 'students.update', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থীর প্রোফাইল সম্পাদনা', 'label_en' => 'Update student records', 'display_order' => 29],
            ['key' => 'students.manage_guardians', 'module' => 'D03_Students', 'label_bn' => 'অভিভাবক সংযুক্তি ও পরিবর্তন', 'label_en' => 'Manage guardians & SMS consent', 'display_order' => 30],
            ['key' => 'students.manage_documents', 'module' => 'D03_Students', 'label_bn' => 'নথি আপলোড ও যাচাই', 'label_en' => 'Manage student documents', 'display_order' => 31],
            ['key' => 'students.manage_identifiers', 'module' => 'D03_Students', 'label_bn' => 'বোর্ড / পূর্ববর্তী রোল নম্বর ব্যবস্থাপনা', 'label_en' => 'Manage external identifiers', 'display_order' => 32],
            ['key' => 'students.manage_status', 'module' => 'D03_Students', 'label_bn' => 'শিক্ষার্থীর অবস্থা পরিবর্তন (সক্রিয়/নিষ্ক্রিয়)', 'label_en' => 'Manage student status lifecycle', 'display_order' => 33],
            ['key' => 'students.review_duplicates', 'module' => 'D03_Students', 'label_bn' => 'সন্দেহভাজন ডুপ্লিকেট অনুমোদন/বাতিল', 'label_en' => 'Review duplicate admissions', 'display_order' => 34],
            ['key' => 'students.financial_lookup', 'module' => 'D03_Students', 'label_bn' => 'আর্থিক ফি জমার জন্য শিক্ষার্থী অনুসন্ধান', 'label_en' => 'Student financial lookup', 'display_order' => 35],

            // D04-D05: Admissions & Promotions
            ['key' => 'admissions.read', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি ও এনরোলমেন্ট পরিদর্শন', 'label_en' => 'View admissions & enrollments', 'display_order' => 24],
            ['key' => 'admissions.create', 'module' => 'D04_Admissions', 'label_bn' => 'নতুন ভর্তি ও এনরোলমেন্ট জমা', 'label_en' => 'Submit admissions & enrollments', 'display_order' => 25],
            ['key' => 'admissions.edit', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি ও এনরোলমেন্ট সম্পাদনা', 'label_en' => 'Edit admission details', 'display_order' => 26],
            ['key' => 'admissions.reallocate', 'module' => 'D04_Admissions', 'label_bn' => 'শাখা (Section) পুনর্বিন্যাস', 'label_en' => 'Reallocate student sections', 'display_order' => 27],
            ['key' => 'admissions.cancel', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি ও এনরোলমেন্ট বাতিল/প্রত্যাখ্যান', 'label_en' => 'Cancel or reject enrollments', 'display_order' => 28],
            ['key' => 'admissions.approve', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি চূড়ান্ত অনুমোদন', 'label_en' => 'Final admission approval', 'display_order' => 29],
            ['key' => 'admissions.process', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি যাচাই ও প্রক্রিয়াকরণ', 'label_en' => 'Process & verify admissions', 'display_order' => 30],
            ['key' => 'admissions.manage', 'module' => 'D04_Admissions', 'label_bn' => 'ভর্তি কার্যক্রম পরিচালনা', 'label_en' => 'Master admission management', 'display_order' => 31],
            ['key' => 'promotions.read', 'module' => 'D05_Promotions', 'label_bn' => 'প্রমোশন তালিকা পরিদর্শন', 'label_en' => 'View promotion eligibility and batches', 'display_order' => 32],
            ['key' => 'promotions.individual.create', 'module' => 'D05_Promotions', 'label_bn' => 'একক শিক্ষার্থী প্রমোশন', 'label_en' => 'Execute individual student promotion', 'display_order' => 33],
            ['key' => 'promotions.batches.create', 'module' => 'D05_Promotions', 'label_bn' => 'বাল্ক প্রমোশন ব্যাচ তৈরি', 'label_en' => 'Create bulk promotion batches', 'display_order' => 34],
            ['key' => 'promotions.batches.execute', 'module' => 'D05_Promotions', 'label_bn' => 'বাল্ক প্রমোশন ব্যাচ কার্যকরকরণ', 'label_en' => 'Execute bulk promotion batches', 'display_order' => 35],
            ['key' => 'promotions.batches.pause_retry', 'module' => 'D05_Promotions', 'label_bn' => 'ব্যাচ বিরতি ও পুনরায় চেষ্টা', 'label_en' => 'Pause, resume, and retry promotion batches', 'display_order' => 36],
            ['key' => 'promotions.audit.read', 'module' => 'D05_Promotions', 'label_bn' => 'প্রমোশন অডিট ও ইতিহাস দেখা', 'label_en' => 'View promotion audit trail and attempts', 'display_order' => 37],
            ['key' => 'promotions.export', 'module' => 'D05_Promotions', 'label_bn' => 'প্রমোশন ফলাফল এক্সপোর্ট', 'label_en' => 'Export promotion results to CSV', 'display_order' => 38],
            ['key' => 'promotions.manage', 'module' => 'D05_Promotions', 'label_bn' => 'প্রমোশন রুলস নির্ধারণ', 'label_en' => 'Configure promotion rules', 'display_order' => 39],
            ['key' => 'promotions.execute', 'module' => 'D05_Promotions', 'label_bn' => 'বার্ষিক প্রমোশন কার্যকরকরণ', 'label_en' => 'Execute annual promotions', 'display_order' => 40],

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
            'institution.read', 'academics.read',
            'students.read', 'students.create', 'students.update',
            'students.manage_guardians', 'students.manage_documents', 'students.manage_identifiers',
            'admissions.read', 'admissions.create', 'admissions.edit', 'admissions.reallocate', 'admissions.cancel',
            'admissions.manage',
            'promotions.read', 'promotions.individual.create', 'promotions.batches.create',
            'promotions.batches.execute', 'promotions.batches.pause_retry', 'promotions.audit.read',
            'promotions.export', 'promotions.manage', 'promotions.execute',
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
            'institution.read', 'academics.read',
            'students.financial_lookup',
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

