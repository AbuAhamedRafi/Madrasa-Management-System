<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use App\Enums\DuplicateReviewStatus;
use App\Enums\GuardianRelation;
use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentDuplicateReview;
use App\Models\StudentGuardian;
use App\Models\StudentIdentifier;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Services\Students\StudentIdAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $allocator = app(StudentIdAllocator::class);
        $auditRepo = app(AuditLogRepository::class);

        // Find or fallback to super admin user
        $adminUser = User::where('username', 'superadmin')->first()
            ?? User::first()
            ?? User::create([
                'public_id' => 'usr_' . uniqid(),
                'name_bn' => 'সুপার অ্যাডমিন',
                'name_en' => 'Super Admin',
                'username' => 'superadmin',
                'email' => 'superadmin@mms.edu.bd',
                'password' => bcrypt('SuperAdmin12345!'),
                'status' => 'ACTIVE',
                'preferred_locale' => 'bn-BD',
            ]);

        $actorId = $adminUser->id;

        // Sample students across all four academic tiers: Ebtedayee, Dakhil, Alim, Fazil
        $studentsData = [
            // ==========================================
            // 1. EBtedayee (Primary / ইবতেদায়ি) Students
            // ==========================================
            [
                'program_code' => 'EBT',
                'class_code' => 'EBT-1',
                'name_bn' => 'আব্দুল্লাহ আল মারুফ',
                'name_en' => 'Abdullah Al Maroof',
                'date_of_birth' => '2019-02-14',
                'gender' => 'MALE',
                'admission_date' => '2026-01-05',
                'status' => StudentStatus::ACTIVE,
                'brc' => '20195512345000101',
                'present_address_bn' => 'বাড়ি # ১২, রোড # ৩, উপশহর, সদর, দিনাজপুর',
                'present_address_en' => 'House 12, Road 3, Upashahar, Sadar, Dinajpur',
                'permanent_address_bn' => 'গ্রাম: চকলোকমান, ডাকঘর: দিনাজপুর সদর, জেলা: দিনাজপুর',
                'permanent_address_en' => 'Choklokman, Dinajpur Sadar, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মোঃ রফিকুল ইসলাম',
                    'name_en' => 'Md. Rafiqul Islam',
                    'phone' => '01711100001',
                    'alternative_phone' => '01811100001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'ব্যবসায়ী',
                    'address_bn' => 'উপশহর, দিনাজপুর',
                ],
                'secondary_guardian' => [
                    'name_bn' => 'মোসাঃ মারুফা বেগম',
                    'name_en' => 'Mst. Marufa Begum',
                    'phone' => '01711100002',
                    'relation' => GuardianRelation::MOTHER,
                    'sms_consent' => false,
                    'occupation' => 'গৃহিণী',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'EBT', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'EBT-1', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'ADMISSION_TOKEN', 'value' => 'ADM-2026-EBT-001', 'issuer' => 'MMS_INTAKE'],
                ],
                'document' => [
                    'type' => DocumentType::BIRTH_CERTIFICATE,
                    'file_name' => 'maroof_birth_certificate.pdf',
                    'reason' => 'Original birth registration certificate verified',
                ],
            ],
            [
                'program_code' => 'EBT',
                'class_code' => 'EBT-3',
                'name_bn' => 'ফাতেমা তুজ জোহরা',
                'name_en' => 'Fatema Tuz Zohra',
                'date_of_birth' => '2017-08-20',
                'gender' => 'FEMALE',
                'admission_date' => '2026-01-08',
                'status' => StudentStatus::ACTIVE,
                'brc' => '20175512345000102',
                'present_address_bn' => 'পুলহাট মোড়, সদর, দিনাজপুর',
                'present_address_en' => 'Pulhat More, Sadar, Dinajpur',
                'permanent_address_bn' => 'পুলহাট মোড়, সদর, দিনাজপুর',
                'permanent_address_en' => 'Pulhat More, Sadar, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মুফতি এনামুল হক',
                    'name_en' => 'Mufti Enamul Haque',
                    'phone' => '01711200001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'মাদ্রাসা শিক্ষক',
                    'address_bn' => 'পুলহাট, দিনাজপুর',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'EBT', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'EBT-3', 'issuer' => 'MMS_ACADEMICS'],
                ],
                'document' => [
                    'type' => DocumentType::BIRTH_CERTIFICATE,
                    'file_name' => 'fatema_brc.pdf',
                    'reason' => 'Government online copy verified',
                ],
            ],
            [
                // Sibling of Student 1 (shares father Md. Rafiqul Islam)
                'program_code' => 'EBT',
                'class_code' => 'EBT-5',
                'name_bn' => 'উসমান গণি',
                'name_en' => 'Usman Gani',
                'date_of_birth' => '2015-11-10',
                'gender' => 'MALE',
                'admission_date' => '2026-01-12',
                'status' => StudentStatus::REGISTERED, // Intake awaiting fee settlement
                'brc' => '20155512345000103',
                'present_address_bn' => 'উপশহর, সদর, দিনাজপুর',
                'present_address_en' => 'Upashahar, Sadar, Dinajpur',
                'permanent_address_bn' => 'উপশহর, সদর, দিনাজপুর',
                'permanent_address_en' => 'Upashahar, Sadar, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মোঃ রফিকুল ইসলাম',
                    'name_en' => 'Md. Rafiqul Islam',
                    'phone' => '01711100001', // Sibling phone sharing
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'ব্যবসায়ী',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'EBT', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'EBT-5', 'issuer' => 'MMS_ACADEMICS'],
                ],
            ],

            // ==========================================
            // 2. DAKHIL (Secondary / দাখিল) Students
            // ==========================================
            [
                'program_code' => 'DKH',
                'class_code' => 'DKH-6',
                'name_bn' => 'মুহাম্মদ তানভীর আহমেদ',
                'name_en' => 'Muhammad Tanvir Ahmed',
                'date_of_birth' => '2013-05-14',
                'gender' => 'MALE',
                'admission_date' => '2026-01-10',
                'status' => StudentStatus::ACTIVE,
                'brc' => '20135512345000201',
                'present_address_bn' => 'বাসুনিয়াপট্টি, কোতোয়ালি, দিনাজপুর',
                'present_address_en' => 'Basuniyapatti, Kotwali, Dinajpur',
                'permanent_address_bn' => 'গ্রাম: মোহনপুর, বিরল, দিনাজপুর',
                'permanent_address_en' => 'Mohanpur, Birol, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মাওলানা তোফাজ্জল হোসেন',
                    'name_en' => 'Mawlana Tofazzal Hossain',
                    'phone' => '01711300001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'ইমাম ও খতিব',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'DKH', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'DKH-6', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'MADRASAH_BOARD', 'value' => 'BMEB-DKH-2026-104', 'issuer' => 'BMEB'],
                ],
                'document' => [
                    'type' => DocumentType::PREVIOUS_TRANSCRIPT,
                    'file_name' => 'ebtedayee_completion_cert.pdf',
                    'reason' => 'PSC/Ebtedayee Completion Board Certificate',
                ],
            ],
            [
                'program_code' => 'DKH',
                'class_code' => 'DKH-9',
                'name_bn' => 'নুসরাত জাহান মিম',
                'name_en' => 'Nusrat Jahan Mim',
                'date_of_birth' => '2011-09-25',
                'gender' => 'FEMALE',
                'admission_date' => '2026-01-15',
                'status' => StudentStatus::ACTIVE,
                'brc' => '20115512345000202',
                'present_address_bn' => 'গণেশতলা, সদর, দিনাজপুর',
                'present_address_en' => 'Ganeshtala, Sadar, Dinajpur',
                'permanent_address_bn' => 'গণেশতলা, সদর, দিনাজপুর',
                'permanent_address_en' => 'Ganeshtala, Sadar, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মোসাঃ বিলকিস আক্তার',
                    'name_en' => 'Mst. Bilkis Akhter',
                    'phone' => '01711400001',
                    'relation' => GuardianRelation::MOTHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'সরকারি চাকরিজীবী',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'DKH', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'DKH-9', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_TRACK', 'value' => 'SCIENCE', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'MADRASAH_BOARD', 'value' => 'BMEB-DKH-2026-209', 'issuer' => 'BMEB'],
                ],
                'document' => [
                    'type' => DocumentType::BIRTH_CERTIFICATE,
                    'file_name' => 'mim_birth_certificate.pdf',
                    'reason' => 'Online BRC verified',
                ],
            ],
            [
                'program_code' => 'DKH',
                'class_code' => 'DKH-8',
                'name_bn' => 'সাকিব আল হাসান',
                'name_en' => 'Sakib Al Hasan',
                'date_of_birth' => '2012-02-18',
                'gender' => 'MALE',
                'admission_date' => '2026-02-01',
                'status' => StudentStatus::REGISTERED, // Intake awaiting fee settlement
                'brc' => '20125512345000203',
                'present_address_bn' => 'পাহাড়পুর, দিনাজপুর',
                'present_address_en' => 'Paharpur, Dinajpur',
                'permanent_address_bn' => 'পাহাড়পুর, দিনাজপুর',
                'permanent_address_en' => 'Paharpur, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'খলিলুর রহমান',
                    'name_en' => 'Khalilur Rahman',
                    'phone' => '01711500001',
                    'relation' => GuardianRelation::LEGAL_GUARDIAN,
                    'sms_consent' => true,
                    'consent_source' => 'OFFICE_VISIT',
                    'occupation' => 'প্রবাসী',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'DKH', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'DKH-8', 'issuer' => 'MMS_ACADEMICS'],
                ],
            ],

            // ==========================================
            // 3. ALIM (Higher Secondary / আলিম) Students
            // ==========================================
            [
                'program_code' => 'ALM',
                'class_code' => 'ALM-Y1',
                'name_bn' => 'মুহাম্মদ আব্দুল্লাহিল কাফী',
                'name_en' => 'Muhammad Abdullahil Kafi',
                'date_of_birth' => '2008-07-12',
                'gender' => 'MALE',
                'admission_date' => '2026-07-02', // 2nd semester (2602 prefix)
                'status' => StudentStatus::ACTIVE,
                'brc' => '20085512345000301',
                'present_address_bn' => 'ব্লক-বি, নিউ টাউন, দিনাজপুর',
                'present_address_en' => 'Block B, New Town, Dinajpur',
                'permanent_address_bn' => 'ব্লক-বি, নিউ টাউন, দিনাজপুর',
                'permanent_address_en' => 'Block B, New Town, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মুফতি শামসুল আলম',
                    'name_en' => 'Mufti Shamsul Alam',
                    'phone' => '01711600001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'অধ্যক্ষ',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'ALM', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'ALM-Y1', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'MADRASAH_BOARD', 'value' => 'BMEB-ALM-2026-4401', 'issuer' => 'BMEB'],
                ],
                'document' => [
                    'type' => DocumentType::PREVIOUS_TRANSCRIPT,
                    'file_name' => 'dakhil_academic_transcript.pdf',
                    'reason' => 'Official Dakhil examination marksheet and certificate',
                ],
            ],
            [
                'program_code' => 'ALM',
                'class_code' => 'ALM-Y2',
                'name_bn' => 'মারিয়াম আক্তার শিমু',
                'name_en' => 'Mariam Akhter Shimu',
                'date_of_birth' => '2007-11-05',
                'gender' => 'FEMALE',
                'admission_date' => '2026-07-05', // 2nd semester (2602 prefix)
                'status' => StudentStatus::ACTIVE,
                'brc' => '20075512345000302',
                'present_address_bn' => 'মির্জাপুর, সদর, দিনাজপুর',
                'present_address_en' => 'Mirzapur, Sadar, Dinajpur',
                'permanent_address_bn' => 'মির্জাপুর, সদর, দিনাজপুর',
                'permanent_address_en' => 'Mirzapur, Sadar, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'আব্দুল ওয়াহাব',
                    'name_en' => 'Abdul Wahab',
                    'phone' => '01711700001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'ব্যবসায়ী',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'ALM', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'ALM-Y2', 'issuer' => 'MMS_ACADEMICS'],
                    ['namespace' => 'MADRASAH_BOARD', 'value' => 'BMEB-ALM-2026-4402', 'issuer' => 'BMEB'],
                ],
                'document' => [
                    'type' => DocumentType::BIRTH_CERTIFICATE,
                    'file_name' => 'shimu_brc_verified.pdf',
                    'reason' => 'Original BRC copy inspected',
                ],
            ],

            // ==========================================
            // 4. FAZIL (Degree / ফাজিল পাস) Students
            // ==========================================
            [
                'program_code' => 'FZ-P',
                'class_code' => 'FZL-Y2',
                'name_bn' => 'মাওলানা মাহমুদুর রহমান',
                'name_en' => 'Mawlana Mahmudur Rahman',
                'date_of_birth' => '2004-04-18',
                'gender' => 'MALE',
                'admission_date' => '2026-07-10', // 2nd semester (2602 prefix)
                'status' => StudentStatus::ACTIVE,
                'brc' => '20045512345000401',
                'present_address_bn' => 'জেল রোড, দিনাজপুর',
                'present_address_en' => 'Jail Road, Dinajpur',
                'permanent_address_bn' => 'জেল রোড, দিনাজপুর',
                'permanent_address_en' => 'Jail Road, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'মাওলানা হাবিবুল্লাহ',
                    'name_en' => 'Mawlana Habibullah',
                    'phone' => '01711800001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'অবসরপ্রাপ্ত শিক্ষক',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'FZ-P', 'issuer' => 'IAU'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'FZL-Y2', 'issuer' => 'IAU'],
                    ['namespace' => 'IAU_REGISTRATION', 'value' => 'IAU-REG-2024-8891', 'issuer' => 'IAU'],
                ],
                'document' => [
                    'type' => DocumentType::PREVIOUS_TRANSCRIPT,
                    'file_name' => 'alim_board_certificate.pdf',
                    'reason' => 'Alim Board Certificate verified for degree admission',
                ],
            ],
            [
                'program_code' => 'FZ-P',
                'class_code' => 'FZL-Y1',
                'name_bn' => 'মুহাম্মদ সাখাওয়াত হোসেন',
                'name_en' => 'Muhammad Sakhawat Hossain',
                'date_of_birth' => '2005-09-30',
                'gender' => 'MALE',
                'admission_date' => '2026-07-12', // 2nd semester (2602 prefix)
                'status' => StudentStatus::REGISTERED, // Intake awaiting fee slip settlement
                'brc' => '20055512345000402',
                'present_address_bn' => 'মহারাজা মোড়, সদর, দিনাজপুর',
                'present_address_en' => 'Maharaja More, Sadar, Dinajpur',
                'permanent_address_bn' => 'গ্রাম: জগদীশপুর, কাহারোল, দিনাজপুর',
                'permanent_address_en' => 'Jagadishpur, Kaharole, Dinajpur',
                'primary_guardian' => [
                    'name_bn' => 'নুরুল ইসলাম',
                    'name_en' => 'Nurul Islam',
                    'phone' => '01711900001',
                    'relation' => GuardianRelation::FATHER,
                    'sms_consent' => true,
                    'consent_source' => 'ADMISSION_FORM',
                    'occupation' => 'কৃষক',
                ],
                'identifiers' => [
                    ['namespace' => 'INTAKE_PROGRAM', 'value' => 'FZ-P', 'issuer' => 'IAU'],
                    ['namespace' => 'TARGET_CLASS', 'value' => 'FZL-Y1', 'issuer' => 'IAU'],
                    ['namespace' => 'IAU_REGISTRATION', 'value' => 'IAU-REG-2026-1022', 'issuer' => 'IAU'],
                ],
            ],
        ];

        // Seed Each Student Idempotently
        foreach ($studentsData as $data) {
            $brcHash = hash('sha256', $data['brc']);

            $student = Student::where('birth_registration_hash', $brcHash)->first();

            if (!$student) {
                $studentId = $allocator->allocate($data['admission_date']);

                $student = Student::create([
                    'student_id' => $studentId,
                    'name_bn' => $data['name_bn'],
                    'name_en' => $data['name_en'],
                    'date_of_birth' => $data['date_of_birth'],
                    'gender' => $data['gender'],
                    'admission_date' => $data['admission_date'],
                    'status' => $data['status'],
                    'birth_registration_ciphertext' => Crypt::encryptString($data['brc']),
                    'birth_registration_hash' => $brcHash,
                    'present_address_bn' => $data['present_address_bn'] ?? null,
                    'present_address_en' => $data['present_address_en'] ?? null,
                    'permanent_address_bn' => $data['permanent_address_bn'] ?? null,
                    'permanent_address_en' => $data['permanent_address_en'] ?? null,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                // Audit Log for Admission Registration
                $auditRepo->log(
                    actionCode: 'STUDENT_REGISTERED',
                    actorUserId: $actorId,
                    subjectUserId: null,
                    beforeMetadata: null,
                    afterMetadata: [
                        'student_id' => $student->student_id,
                        'name_bn' => $student->name_bn,
                        'name_en' => $student->name_en,
                        'status' => $student->status->value,
                        'intake_program' => $data['program_code'],
                        'intake_class' => $data['class_code'],
                    ],
                    reason: "Initial student registration for {$data['program_code']} ({$data['class_code']})",
                    entityType: 'Student',
                    entityId: $student->id
                );
            }

            // 1. Primary Guardian
            $pgData = $data['primary_guardian'];
            $primaryGuardian = Guardian::where('phone', $pgData['phone'])->first();

            if (!$primaryGuardian) {
                $primaryGuardian = Guardian::create([
                    'name_bn' => $pgData['name_bn'],
                    'name_en' => $pgData['name_en'],
                    'phone' => $pgData['phone'],
                    'alternative_phone' => $pgData['alternative_phone'] ?? null,
                    'address_bn' => $pgData['address_bn'] ?? null,
                    'occupation' => $pgData['occupation'] ?? null,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }

            // Link primary guardian if not already linked
            $existingLink = StudentGuardian::where('student_id', $student->id)
                ->where('guardian_id', $primaryGuardian->id)
                ->first();

            if (!$existingLink) {
                StudentGuardian::create([
                    'student_id' => $student->id,
                    'guardian_id' => $primaryGuardian->id,
                    'relation' => $pgData['relation']->value,
                    'is_primary' => true,
                    'sms_consent' => $pgData['sms_consent'],
                    'consent_at' => $pgData['sms_consent'] ? now() : null,
                    'consent_source' => $pgData['consent_source'] ?? 'ADMISSION_FORM',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }

            // 2. Secondary Guardian (if present)
            if (!empty($data['secondary_guardian'])) {
                $sgData = $data['secondary_guardian'];
                $secondaryGuardian = Guardian::where('phone', $sgData['phone'])->first();

                if (!$secondaryGuardian) {
                    $secondaryGuardian = Guardian::create([
                        'name_bn' => $sgData['name_bn'],
                        'name_en' => $sgData['name_en'],
                        'phone' => $sgData['phone'],
                        'occupation' => $sgData['occupation'] ?? null,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }

                $existingSecLink = StudentGuardian::where('student_id', $student->id)
                    ->where('guardian_id', $secondaryGuardian->id)
                    ->first();

                if (!$existingSecLink) {
                    StudentGuardian::create([
                        'student_id' => $student->id,
                        'guardian_id' => $secondaryGuardian->id,
                        'relation' => $sgData['relation']->value,
                        'is_primary' => false,
                        'sms_consent' => $sgData['sms_consent'],
                        'consent_at' => $sgData['sms_consent'] ? now() : null,
                        'consent_source' => 'ADMISSION_FORM',
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }
            }

            // 3. Identifiers
            if (!empty($data['identifiers'])) {
                foreach ($data['identifiers'] as $ident) {
                    StudentIdentifier::firstOrCreate(
                        [
                            'namespace' => $ident['namespace'],
                            'value_normalized' => strtoupper(trim($ident['value'])),
                        ],
                        [
                            'student_id' => $student->id,
                            'value_raw' => $ident['value'],
                            'issuer' => $ident['issuer'] ?? null,
                            'created_by' => $actorId,
                        ]
                    );
                }
            }

            // 4. Sample Document
            if (!empty($data['document'])) {
                $docData = $data['document'];
                $existingDoc = StudentDocument::where('student_id', $student->id)
                    ->where('file_name', $docData['file_name'])
                    ->first();

                if (!$existingDoc) {
                    $pdfContent = "%PDF-1.4\n1 0 obj\n<< /Title (Sample Document for {$student->student_id}) >>\nendobj\ntrailer\n<<>>\n%%EOF";
                    $uuid = Str::uuid()->toString();
                    $relPath = "student_documents/{$student->id}/{$uuid}.pdf";
                    Storage::disk('local')->put($relPath, $pdfContent);

                    StudentDocument::create([
                        'student_id' => $student->id,
                        'type' => $docData['type'],
                        'file_name' => $docData['file_name'],
                        'storage_key' => $relPath,
                        'mime_type' => 'application/pdf',
                        'size_bytes' => strlen($pdfContent),
                        'sha256' => hash('sha256', $pdfContent),
                        'verification_status' => DocumentVerificationStatus::VERIFIED,
                        'verification_reason' => $docData['reason'],
                        'uploaded_by' => $actorId,
                        'verified_by' => $actorId,
                    ]);
                }
            }
        }

        // ==========================================
        // 5. Sample Pending Duplicate Review Ticket
        // ==========================================
        $firstStudent = Student::first();
        if ($firstStudent) {
            $existingReview = StudentDuplicateReview::where('status', DuplicateReviewStatus::PENDING)->first();

            if (!$existingReview) {
                StudentDuplicateReview::create([
                    'proposed_payload_hash' => hash('sha256', 'sample_duplicate_candidate_' . $firstStudent->id),
                    'candidate_matches' => [
                        [
                            'student_id' => $firstStudent->student_id,
                            'name_bn' => $firstStudent->name_bn,
                            'name_en' => $firstStudent->name_en,
                            'date_of_birth' => $firstStudent->date_of_birth->format('Y-m-d'),
                            'match_reasons' => [
                                'Identical date of birth (' . $firstStudent->date_of_birth->format('Y-m-d') . ')',
                                'Phonetic match on student name',
                                'Matching guardian contact phone (01711100001)',
                            ],
                            'confidence' => 0.85,
                        ],
                    ],
                    'status' => DuplicateReviewStatus::PENDING,
                    'submitted_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
