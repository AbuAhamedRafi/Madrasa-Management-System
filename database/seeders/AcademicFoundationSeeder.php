<?php

namespace Database\Seeders;

use App\Enums\AcademicScheme;
use App\Enums\CohortStatus;
use App\Enums\PeriodState;
use App\Enums\ProgramState;
use App\Models\AcademicCohort;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicTrack;
use App\Models\Department;
use App\Models\EducationAuthority;
use App\Models\EducationLevel;
use App\Models\Institution;
use App\Models\OfferingSection;
use App\Models\ProgramClass;
use Illuminate\Database\Seeder;

class AcademicFoundationSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Single Institution Record
        Institution::updateOrCreate(
            ['code' => 'MMS-001'],
            [
                'name_bn' => 'জামিয়া আরাবিয়া হাফিজিয়া ক্বারিয়ানা মাদ্রাসা (লিল্লাহ বোডিং ও এতিমখানা)',
                'name_en' => 'Jamia Arabia Hafizia Qariyana Madrasa (Lillah Boarding & Orphanage)',
                'address_bn' => 'সদর, দিনাজপুর, বাংলাদেশ',
                'address_en' => 'Sadar, Dinajpur, Bangladesh',
                'phone' => '01700000000',
                'default_locale' => 'bn-BD',
                'timezone' => 'Asia/Dhaka',
            ]
        );

        // 2. Education Authorities (Bangladesh-aligned defaults)
        $bmeb = EducationAuthority::updateOrCreate(
            ['code' => 'BMEB'],
            [
                'name_bn' => 'বাংলাদেশ মাদ্রাসা শিক্ষা বোর্ড',
                'name_en' => 'Bangladesh Madrasah Education Board',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $iau = EducationAuthority::updateOrCreate(
            ['code' => 'IAU'],
            [
                'name_bn' => 'ইসলামি আরবি বিশ্ববিদ্যালয়',
                'name_en' => 'Islamic Arabic University',
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        // 3. Education Levels
        $levelsData = [
            ['authority_id' => $bmeb->id, 'code' => 'EBT', 'name_bn' => 'ইবতেদায়ি', 'name_en' => 'Primary / Ebtedayee', 'sort_order' => 1],
            ['authority_id' => $bmeb->id, 'code' => 'DKH', 'name_bn' => 'দাখিল', 'name_en' => 'Secondary / Dakhil', 'sort_order' => 2],
            ['authority_id' => $bmeb->id, 'code' => 'ALM', 'name_bn' => 'আলিম', 'name_en' => 'Higher Secondary / Alim', 'sort_order' => 3],
            ['authority_id' => $iau->id, 'code' => 'FZL', 'name_bn' => 'ফাজিল', 'name_en' => 'Degree / Fazil', 'sort_order' => 4],
            ['authority_id' => $iau->id, 'code' => 'KML', 'name_bn' => 'কামিল', 'name_en' => 'Postgraduate / Kamil', 'sort_order' => 5],
        ];

        $levels = [];
        foreach ($levelsData as $lvl) {
            $levels[$lvl['code']] = EducationLevel::updateOrCreate(
                ['authority_id' => $lvl['authority_id'], 'code' => $lvl['code']],
                [
                    'name_bn' => $lvl['name_bn'],
                    'name_en' => $lvl['name_en'],
                    'is_active' => true,
                    'sort_order' => $lvl['sort_order'],
                ]
            );
        }

        // 4. Internal Madrasa Departments
        $departmentsData = [
            ['code' => 'PRIMARY', 'name_bn' => 'প্রাথমিক বিভাগ', 'name_en' => 'Primary Department', 'sort_order' => 1],
            ['code' => 'SECONDARY', 'name_bn' => 'মাধ্যমিক বিভাগ', 'name_en' => 'Secondary Department', 'sort_order' => 2],
            ['code' => 'HSC', 'name_bn' => 'উচ্চমাধ্যমিক বিভাগ', 'name_en' => 'Higher Secondary Department', 'sort_order' => 3],
            ['code' => 'HIGHER', 'name_bn' => 'উচ্চশিক্ষা বিভাগ', 'name_en' => 'Higher Studies Department', 'sort_order' => 4],
        ];

        $departments = [];
        foreach ($departmentsData as $dept) {
            $departments[$dept['code']] = Department::updateOrCreate(
                ['code' => $dept['code']],
                [
                    'name_bn' => $dept['name_bn'],
                    'name_en' => $dept['name_en'],
                    'sort_order' => $dept['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        // 5. Default Programs (Ebtedayee, Dakhil, Alim, Fazil Pass)
        $ebtProgram = AcademicProgram::updateOrCreate(
            ['code' => 'EBT'],
            [
                'department_id' => $departments['PRIMARY']->id,
                'education_level_id' => $levels['EBT']->id,
                'name_bn' => 'ইবতেদায়ি',
                'name_en' => 'Ebtedayee',
                'scheme' => AcademicScheme::CALENDAR_YEAR,
                'duration_years' => 5,
                'state' => ProgramState::ACTIVE,
            ]
        );

        $dkhProgram = AcademicProgram::updateOrCreate(
            ['code' => 'DKH'],
            [
                'department_id' => $departments['SECONDARY']->id,
                'education_level_id' => $levels['DKH']->id,
                'name_bn' => 'দাখিল',
                'name_en' => 'Dakhil',
                'scheme' => AcademicScheme::CALENDAR_YEAR,
                'duration_years' => 5,
                'state' => ProgramState::ACTIVE,
            ]
        );

        $almProgram = AcademicProgram::updateOrCreate(
            ['code' => 'ALM'],
            [
                'department_id' => $departments['HSC']->id,
                'education_level_id' => $levels['ALM']->id,
                'name_bn' => 'আলিম',
                'name_en' => 'Alim',
                'scheme' => AcademicScheme::SESSION_BASED,
                'duration_years' => 2,
                'state' => ProgramState::ACTIVE,
            ]
        );

        $fzlProgram = AcademicProgram::updateOrCreate(
            ['code' => 'FZ-P'],
            [
                'department_id' => $departments['HIGHER']->id,
                'education_level_id' => $levels['FZL']->id,
                'name_bn' => 'ফাজিল (পাস)',
                'name_en' => 'Fazil (Pass)',
                'scheme' => AcademicScheme::SESSION_BASED,
                'duration_years' => 3,
                'state' => ProgramState::ACTIVE,
            ]
        );

        // 6. Ebtedayee Classes (Class 1 to 5), 2026 Period, Offerings & Sections
        $ebtedayeeClassesData = [
            ['code' => 'EBT-1', 'name_bn' => 'ইবতেদায়ি ১ম শ্রেণি', 'name_en' => 'Ebtedayee Class 1', 'stage_number' => 1, 'sort_order' => 1],
            ['code' => 'EBT-2', 'name_bn' => 'ইবতেদায়ি ২য় শ্রেণি', 'name_en' => 'Ebtedayee Class 2', 'stage_number' => 2, 'sort_order' => 2],
            ['code' => 'EBT-3', 'name_bn' => 'ইবতেদায়ি ৩য় শ্রেণি', 'name_en' => 'Ebtedayee Class 3', 'stage_number' => 3, 'sort_order' => 3],
            ['code' => 'EBT-4', 'name_bn' => 'ইবতেদায়ি ৪র্থ শ্রেণি', 'name_en' => 'Ebtedayee Class 4', 'stage_number' => 4, 'sort_order' => 4],
            ['code' => 'EBT-5', 'name_bn' => 'ইবতেদায়ি ৫ম শ্রেণি', 'name_en' => 'Ebtedayee Class 5', 'stage_number' => 5, 'sort_order' => 5],
        ];
        $ebtClasses = [];
        foreach ($ebtedayeeClassesData as $ec) {
            $ebtClasses[$ec['code']] = ProgramClass::updateOrCreate(
                ['program_id' => $ebtProgram->id, 'code' => $ec['code']],
                [
                    'name_bn' => $ec['name_bn'],
                    'name_en' => $ec['name_en'],
                    'stage_number' => $ec['stage_number'],
                    'sort_order' => $ec['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        $ebtPeriod2026 = AcademicPeriod::updateOrCreate(
            ['program_id' => $ebtProgram->id, 'calendar_year' => 2026],
            [
                'cohort_id' => null,
                'label' => '২০২৬ শিক্ষাবর্ষ (2026)',
                'study_year_number' => null,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'state' => PeriodState::OPEN,
                'lock_version' => 1,
            ]
        );

        foreach ($ebtClasses as $ebtClass) {
            $ebtOffering = AcademicOffering::updateOrCreate(
                [
                    'academic_period_id' => $ebtPeriod2026->id,
                    'program_class_id' => $ebtClass->id,
                    'academic_track_id' => null,
                ],
                ['is_active' => true]
            );

            OfferingSection::updateOrCreate(
                ['academic_offering_id' => $ebtOffering->id, 'code' => 'SEC-A'],
                ['name_bn' => 'ক শাখা', 'name_en' => 'Section A', 'capacity' => 40, 'is_active' => true]
            );
        }

        // 7. Dakhil Classes (Class 6 to 10), Tracks, 2026 Period, Offerings & Sections
        $dakhilClassesData = [
            ['code' => 'DKH-6', 'name_bn' => 'দাখিল ৬ষ্ঠ শ্রেণি', 'name_en' => 'Dakhil Class 6', 'stage_number' => 1, 'sort_order' => 1],
            ['code' => 'DKH-7', 'name_bn' => 'দাখিল ৭ম শ্রেণি', 'name_en' => 'Dakhil Class 7', 'stage_number' => 2, 'sort_order' => 2],
            ['code' => 'DKH-8', 'name_bn' => 'দাখিল ৮ম শ্রেণি', 'name_en' => 'Dakhil Class 8', 'stage_number' => 3, 'sort_order' => 3],
            ['code' => 'DKH-9', 'name_bn' => 'দাখিল ৯ম শ্রেণি', 'name_en' => 'Dakhil Class 9', 'stage_number' => 4, 'sort_order' => 4],
            ['code' => 'DKH-10', 'name_bn' => 'দাখিল ১০ম শ্রেণি', 'name_en' => 'Dakhil Class 10', 'stage_number' => 5, 'sort_order' => 5],
        ];
        $dkhClasses = [];
        foreach ($dakhilClassesData as $dc) {
            $dkhClasses[$dc['code']] = ProgramClass::updateOrCreate(
                ['program_id' => $dkhProgram->id, 'code' => $dc['code']],
                [
                    'name_bn' => $dc['name_bn'],
                    'name_en' => $dc['name_en'],
                    'stage_number' => $dc['stage_number'],
                    'sort_order' => $dc['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        $dkhGen = AcademicTrack::updateOrCreate(
            ['program_id' => $dkhProgram->id, 'code' => 'GENERAL'],
            ['name_bn' => 'সাধারণ', 'name_en' => 'General', 'is_active' => true]
        );
        $dkhSci = AcademicTrack::updateOrCreate(
            ['program_id' => $dkhProgram->id, 'code' => 'SCIENCE'],
            ['name_bn' => 'বিজ্ঞান', 'name_en' => 'Science', 'is_active' => true]
        );

        $dkhPeriod2026 = AcademicPeriod::updateOrCreate(
            ['program_id' => $dkhProgram->id, 'calendar_year' => 2026],
            [
                'cohort_id' => null,
                'label' => '২০২৬ শিক্ষাবর্ষ (2026)',
                'study_year_number' => null,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'state' => PeriodState::OPEN,
                'lock_version' => 1,
            ]
        );

        foreach (['DKH-6', 'DKH-7', 'DKH-8'] as $code) {
            $off = AcademicOffering::updateOrCreate(
                [
                    'academic_period_id' => $dkhPeriod2026->id,
                    'program_class_id' => $dkhClasses[$code]->id,
                    'academic_track_id' => null,
                ],
                ['is_active' => true]
            );
            OfferingSection::updateOrCreate(
                ['academic_offering_id' => $off->id, 'code' => 'SEC-A'],
                ['name_bn' => 'ক শাখা', 'name_en' => 'Section A', 'capacity' => 50, 'is_active' => true]
            );
        }

        foreach (['DKH-9', 'DKH-10'] as $code) {
            foreach ([$dkhGen, $dkhSci] as $trk) {
                $off = AcademicOffering::updateOrCreate(
                    [
                        'academic_period_id' => $dkhPeriod2026->id,
                        'program_class_id' => $dkhClasses[$code]->id,
                        'academic_track_id' => $trk->id,
                    ],
                    ['is_active' => true]
                );
                OfferingSection::updateOrCreate(
                    ['academic_offering_id' => $off->id, 'code' => 'SEC-A'],
                    ['name_bn' => 'ক শাখা', 'name_en' => 'Section A', 'capacity' => 45, 'is_active' => true]
                );
            }
        }

        // 8. Alim Classes, Tracks, Cohorts, Periods, Offerings & Sections
        $alimY1 = ProgramClass::updateOrCreate(
            ['program_id' => $almProgram->id, 'code' => 'ALM-Y1'],
            [
                'name_bn' => 'আলিম ১ম বর্ষ',
                'name_en' => 'Alim First Year',
                'stage_number' => 1,
                'sort_order' => 1,
                'is_active' => true,
            ]
        );
        ProgramClass::updateOrCreate(
            ['program_id' => $almProgram->id, 'code' => 'ALM-Y2'],
            [
                'name_bn' => 'আলিম ২য় বর্ষ',
                'name_en' => 'Alim Second Year',
                'stage_number' => 2,
                'sort_order' => 2,
                'is_active' => true,
            ]
        );

        $almSci = AcademicTrack::updateOrCreate(
            ['program_id' => $almProgram->id, 'code' => 'SCIENCE'],
            ['name_bn' => 'বিজ্ঞান', 'name_en' => 'Science', 'is_active' => true]
        );
        $almHum = AcademicTrack::updateOrCreate(
            ['program_id' => $almProgram->id, 'code' => 'HUMANITIES'],
            ['name_bn' => 'মানবিক', 'name_en' => 'Humanities', 'is_active' => true]
        );

        $almCohort2627 = AcademicCohort::updateOrCreate(
            ['program_id' => $almProgram->id, 'label' => '2026-27'],
            [
                'intake_year' => 2026,
                'description' => 'Admission cohort 2026-27',
                'status' => CohortStatus::ACTIVE,
            ]
        );

        $almPeriodY1 = AcademicPeriod::updateOrCreate(
            ['cohort_id' => $almCohort2627->id, 'study_year_number' => 1],
            [
                'program_id' => $almProgram->id,
                'label' => 'আলিম ১ম বর্ষ (২০২৬-২৭)',
                'calendar_year' => null,
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'state' => PeriodState::OPEN,
                'lock_version' => 1,
            ]
        );

        AcademicPeriod::updateOrCreate(
            ['cohort_id' => $almCohort2627->id, 'study_year_number' => 2],
            [
                'program_id' => $almProgram->id,
                'label' => 'আলিম ২য় বর্ষ (২০২৬-২৭)',
                'calendar_year' => null,
                'start_date' => '2027-07-01',
                'end_date' => '2028-06-30',
                'state' => PeriodState::DRAFT,
                'lock_version' => 1,
            ]
        );

        $offSci = AcademicOffering::updateOrCreate(
            [
                'academic_period_id' => $almPeriodY1->id,
                'program_class_id' => $alimY1->id,
                'academic_track_id' => $almSci->id,
            ],
            ['is_active' => true]
        );
        OfferingSection::updateOrCreate(
            ['academic_offering_id' => $offSci->id, 'code' => 'SEC-A'],
            ['name_bn' => 'ক শাখা', 'name_en' => 'Section A', 'capacity' => 50, 'is_active' => true]
        );
        OfferingSection::updateOrCreate(
            ['academic_offering_id' => $offSci->id, 'code' => 'SEC-B'],
            ['name_bn' => 'খ শাখা', 'name_en' => 'Section B', 'capacity' => 45, 'is_active' => true]
        );

        $offHum = AcademicOffering::updateOrCreate(
            [
                'academic_period_id' => $almPeriodY1->id,
                'program_class_id' => $alimY1->id,
                'academic_track_id' => $almHum->id,
            ],
            ['is_active' => true]
        );
        OfferingSection::updateOrCreate(
            ['academic_offering_id' => $offHum->id, 'code' => 'SEC-A'],
            ['name_bn' => 'ক শাখা', 'name_en' => 'Section A', 'capacity' => 55, 'is_active' => true]
        );

        // 9. Fazil (Pass) Classes, Cohort, Period, Offering & Section
        $fzlY1 = ProgramClass::updateOrCreate(
            ['program_id' => $fzlProgram->id, 'code' => 'FZL-Y1'],
            ['name_bn' => 'ফাজিল ১ম বর্ষ', 'name_en' => 'Fazil 1st Year', 'stage_number' => 1, 'sort_order' => 1, 'is_active' => true]
        );
        ProgramClass::updateOrCreate(
            ['program_id' => $fzlProgram->id, 'code' => 'FZL-Y2'],
            ['name_bn' => 'ফাজিল ২য় বর্ষ', 'name_en' => 'Fazil 2nd Year', 'stage_number' => 2, 'sort_order' => 2, 'is_active' => true]
        );
        ProgramClass::updateOrCreate(
            ['program_id' => $fzlProgram->id, 'code' => 'FZL-Y3'],
            ['name_bn' => 'ফাজিল ৩য় বর্ষ', 'name_en' => 'Fazil 3rd Year', 'stage_number' => 3, 'sort_order' => 3, 'is_active' => true]
        );

        $fzlCohort = AcademicCohort::updateOrCreate(
            ['program_id' => $fzlProgram->id, 'label' => '2026-27'],
            [
                'intake_year' => 2026,
                'description' => 'Fazil Pass Cohort 2026-27',
                'status' => CohortStatus::ACTIVE,
            ]
        );

        $fzlPeriodY1 = AcademicPeriod::updateOrCreate(
            ['cohort_id' => $fzlCohort->id, 'study_year_number' => 1],
            [
                'program_id' => $fzlProgram->id,
                'label' => 'ফাজিল ১ম বর্ষ (২০২৬-২৭)',
                'calendar_year' => null,
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'state' => PeriodState::OPEN,
                'lock_version' => 1,
            ]
        );

        $fzlOff = AcademicOffering::updateOrCreate(
            [
                'academic_period_id' => $fzlPeriodY1->id,
                'program_class_id' => $fzlY1->id,
                'academic_track_id' => null,
            ],
            ['is_active' => true]
        );
        OfferingSection::updateOrCreate(
            ['academic_offering_id' => $fzlOff->id, 'code' => 'SEC-A'],
            ['name_bn' => 'ক শাখা', 'name_en' => 'Section A', 'capacity' => 60, 'is_active' => true]
        );
    }
}
