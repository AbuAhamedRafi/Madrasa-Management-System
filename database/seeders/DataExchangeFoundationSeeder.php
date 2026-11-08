<?php

namespace Database\Seeders;

use App\Enums\ImportMode;
use App\Models\ImportTemplate;
use Illuminate\Database\Seeder;

class DataExchangeFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'students_create',
                'name_bn' => 'নতুন শিক্ষার্থী নিবন্ধন টেমপ্লেট',
                'name_en' => 'New Student Registration Template',
                'mode' => ImportMode::CREATE_ONLY,
                'version' => '1.0',
                'locale' => 'bn',
                'scheme' => 'ALL',
                'is_active' => true,
                'schema_json' => [
                    'schemaVersion' => '1.0',
                    'mode' => 'CREATE_ONLY',
                    'header_row' => 1,
                    'required_fields' => [
                        'name_bn',
                        'name_en',
                        'date_of_birth',
                        'gender',
                        'primary_guardian.name_bn',
                        'primary_guardian.name_en',
                        'primary_guardian.relation',
                        'primary_guardian.phone',
                    ],
                    'optional_fields' => [
                        'birth_registration_number',
                        'present_address_bn',
                        'present_address_en',
                        'permanent_address_bn',
                        'permanent_address_en',
                        'identifiers.legacy_id',
                    ],
                    'forbidden_fields' => [
                        'student_id',
                        'admission_status',
                        'cash_paid',
                        'receipt_no',
                        'invoice_amount',
                    ],
                    'rules' => [
                        'phone_format' => 'Must be valid BD phone retaining text format',
                        'date_format' => 'YYYY-MM-DD ISO format',
                        'gender_values' => ['MALE', 'FEMALE'],
                    ],
                ],
                'checksum' => hash('sha256', 'students_create_v1.0'),
            ],
            [
                'code' => 'student_update',
                'name_bn' => 'বিদ্যমান শিক্ষার্থী তথ্য আপডেট টেমপ্লেট',
                'name_en' => 'Existing Student Update Template',
                'mode' => ImportMode::MATCH_UPDATE,
                'version' => '1.0',
                'locale' => 'bn',
                'scheme' => 'ALL',
                'is_active' => true,
                'schema_json' => [
                    'schemaVersion' => '1.0',
                    'mode' => 'MATCH_UPDATE',
                    'header_row' => 1,
                    'lookup_keys' => [
                        'system_student_id',
                        'identifiers.legacy_id',
                    ],
                    'allowed_update_fields' => [
                        'name_bn',
                        'name_en',
                        'present_address_bn',
                        'present_address_en',
                        'permanent_address_bn',
                        'permanent_address_en',
                        'primary_guardian.phone',
                    ],
                    'forbidden_fields' => [
                        'student_id',
                        'total_paid',
                        'paid_status',
                        'invoice_amount',
                    ],
                ],
                'checksum' => hash('sha256', 'student_update_v1.0'),
            ],
            [
                'code' => 'enrollment_stage',
                'name_bn' => 'শিক্ষাবর্ষ ভর্তি ও এনরোলমেন্ট টেমপ্লেট',
                'name_en' => 'Academic Enrollment Staging Template',
                'mode' => ImportMode::ENROLL_ONLY,
                'version' => '1.0',
                'locale' => 'bn',
                'scheme' => 'ALL',
                'is_active' => true,
                'schema_json' => [
                    'schemaVersion' => '1.0',
                    'mode' => 'ENROLL_ONLY',
                    'header_row' => 1,
                    'required_fields' => [
                        'system_student_id',
                        'academic_program_code',
                        'academic_period_code',
                        'class_code',
                        'offering_section_code',
                    ],
                    'optional_fields' => [
                        'cohort_code',
                    ],
                    'forbidden_fields' => [
                        'paid',
                        'approved',
                        'payment_cleared',
                        'billing_cleared',
                        'fee_totals',
                    ],
                ],
                'checksum' => hash('sha256', 'enrollment_stage_v1.0'),
            ],
        ];

        foreach ($templates as $data) {
            ImportTemplate::updateOrCreate(
                ['code' => $data['code'], 'version' => $data['version'], 'locale' => $data['locale']],
                $data
            );
        }
    }
}
