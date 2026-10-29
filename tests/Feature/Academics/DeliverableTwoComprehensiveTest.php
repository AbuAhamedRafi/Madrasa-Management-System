<?php

namespace Tests\Feature\Academics;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliverableTwoComprehensiveTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $staff;
    protected User $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AcademicFoundationSeeder::class);

        $this->admin = $this->createUserWithRole('d02.admin', 'ADMIN');
        $this->staff = $this->createUserWithRole('d02.staff', 'OFFICE_STAFF');
        $this->accounts = $this->createUserWithRole('d02.accounts', 'ACCOUNTS');
    }

    protected function createUserWithRole(string $username, string $roleKey): User
    {
        $user = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'টেস্ট ইউজার',
            'name_en' => 'Test User',
            'username' => $username,
            'email' => $username . '@mms.test',
            'password' => bcrypt('TestPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);

        $role = Role::where('key', $roleKey)->firstOrFail();
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user->fresh(['roles.permissions', 'permissionGrants']);
    }

    /**
     * AT-D02-01, AT-D02-02, AT-D02-03: Institution Profile, Role Permissions & Validated Logo Upload
     */
    public function test_at_d02_01_02_03_institution_profile_and_logo_validation(): void
    {
        Storage::fake('public');

        // AT-D02-29: Anonymous API access returns 401
        $this->getJson('/api/v1/academics/institution')->assertStatus(401);

        // AT-D02-02: Staff and Accounts may read institution profile
        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/academics/institution')
            ->assertOk()
            ->assertJsonPath('data.code', 'MMS-001');

        $this->actingAs($this->accounts, 'sanctum')
            ->getJson('/api/v1/academics/institution')
            ->assertOk();

        // AT-D02-02 & AT-D02-29: Non-Admin cannot edit institution profile (403 FORBIDDEN)
        $this->actingAs($this->staff, 'sanctum')
            ->patchJson('/api/v1/admin/institution', ['name_en' => 'Unauthorized Change'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($this->accounts, 'sanctum')
            ->patchJson('/api/v1/admin/institution', ['name_en' => 'Unauthorized Change'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // AT-D02-01: Admin saves bilingual name/address and locale, and reloads
        $updateRes = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/institution', [
                'name_bn' => 'দারুল উলুম আলিম মাদ্রাসা',
                'name_en' => 'Darul Uloom Alim Madrasa',
                'address_bn' => 'মতিঝিল, ঢাকা',
                'address_en' => 'Motijheel, Dhaka',
                'phone' => '01811223344',
                'default_locale' => 'en',
                'timezone' => 'Asia/Dhaka',
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.name_bn', 'দারুল উলুম আলিম মাদ্রাসা')
            ->assertJsonPath('data.name_en', 'Darul Uloom Alim Madrasa')
            ->assertJsonPath('data.default_locale', 'en');

        // Immutable institution code rejected
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/institution', ['code' => 'MMS-999'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // AT-D02-03: Valid PNG logo accepted (1x1 valid PNG binary)
        $validPngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $validPng = UploadedFile::fake()->createWithContent('logo.png', $validPngBytes);
        $logoRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/institution/logo', ['logo' => $validPng]);
        $logoRes->assertOk();
        $this->assertStringStartsWith('/storage/institution/logo_', $logoRes->json('data.logo_url'));

        // AT-D02-03: Spoofed non-image file with .png extension rejected
        $spoofed = UploadedFile::fake()->createWithContent('spoofed.png', '<?php echo "not an image"; ?>');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/institution/logo', ['logo' => $spoofed])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // AT-D02-03: Oversized upload (> 2MB) rejected
        $oversized = UploadedFile::fake()->create('huge.png', 3000, 'image/png');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/institution/logo', ['logo' => $oversized])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * AT-D02-04, AT-D02-05, AT-D02-06: Authorities, Levels, and Reusable Departments
     */
    public function test_at_d02_04_05_06_authorities_levels_and_departments(): void
    {
        // AT-D02-04: Authority code uniqueness is case-insensitive after normalization
        $authRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/authorities', [
                'code' => '  befaq-bd ',
                'name_bn' => 'বেফাকুল মাদারিসিল আরাবিয়া',
                'name_en' => 'Befaqul Madarisil Arabia',
            ]);
        $authRes->assertStatus(201)
            ->assertJsonPath('data.code', 'BEFAQ-BD');
        $authorityId = $authRes->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/authorities', [
                'code' => 'BEFAQ-BD',
                'name_bn' => 'ডুপ্লিকেট',
                'name_en' => 'Duplicate',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        // AT-D02-05: Education level references one existing authority; wrong authority rejected
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/levels', [
                'authority_id' => 999999,
                'code' => 'TAKMIL',
                'name_bn' => 'তাকমিল',
                'name_en' => 'Takmil',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $level1Res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/levels', [
                'authority_id' => $authorityId,
                'code' => 'takmil',
                'name_bn' => 'তাকমিল',
                'name_en' => 'Takmil',
            ]);
        $level1Res->assertStatus(201)->assertJsonPath('data.code', 'TAKMIL');
        $level1Id = $level1Res->json('data.id');

        // Duplicate level code under same authority rejected
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/levels', [
                'authority_id' => $authorityId,
                'code' => 'TAKMIL',
                'name_bn' => 'তাকমিল ২',
                'name_en' => 'Takmil 2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        $level2Res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/levels', [
                'authority_id' => $authorityId,
                'code' => 'FAZILAT',
                'name_bn' => 'ফযিলত',
                'name_en' => 'Fazilat',
            ]);
        $level2Id = $level2Res->json('data.id');

        // AT-D02-06: Department can be reused by programs under different education levels
        $deptRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/departments', [
                'code' => 'arabic-studies',
                'name_bn' => 'আরবি স্টাডিজ বিভাগ',
                'name_en' => 'Arabic Studies Department',
            ]);
        $deptRes->assertStatus(201)->assertJsonPath('data.code', 'ARABIC-STUDIES');
        $deptId = $deptRes->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/programs', [
                'code' => 'PRG-TAK',
                'department_id' => $deptId,
                'education_level_id' => $level1Id,
                'name_bn' => 'তাকমিল প্রোগ্রাম',
                'name_en' => 'Takmil Program',
                'scheme' => 'SESSION_BASED',
                'duration_years' => 1,
            ])->assertStatus(201);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/programs', [
                'code' => 'PRG-FAZ',
                'department_id' => $deptId,
                'education_level_id' => $level2Id,
                'name_bn' => 'ফযিলত প্রোগ্রাম',
                'name_en' => 'Fazilat Program',
                'scheme' => 'CALENDAR_YEAR',
                'duration_years' => 2,
            ])->assertStatus(201);
    }

    /**
     * AT-D02-07, AT-D02-08, AT-D02-09, AT-D02-10: Programs, Scheme Lock, Classes & Tracks
     */
    public function test_at_d02_07_08_09_10_programs_scheme_lock_classes_and_tracks(): void
    {
        $deptId = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/academics/departments')
            ->json('data.0.id');
        $levelId = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/academics/levels')
            ->json('data.0.id');

        // AT-D02-07: Missing required fields rejected
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/programs', [
                'code' => 'INVALID',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Valid program creation
        $progRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/programs', [
                'code' => 'HIFZ-01',
                'department_id' => $deptId,
                'education_level_id' => $levelId,
                'name_bn' => 'হিফযুল কুরআন',
                'name_en' => 'Hifzul Quran',
                'scheme' => 'SESSION_BASED',
                'duration_years' => 3,
            ]);
        $progRes->assertStatus(201)->assertJsonPath('data.scheme', 'SESSION_BASED');
        $programId = $progRes->json('data.id');

        // Create a cohort so dependent record exists
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/cohorts', [
                'program_id' => $programId,
                'label' => '2026-27',
                'intake_year' => 2026,
            ])->assertStatus(201);

        // AT-D02-08: Program scheme cannot change after a dependent period/cohort exists (409 ACADEMIC_SCHEME_IMMUTABLE)
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/programs/{$programId}", [
                'scheme' => 'CALENDAR_YEAR',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACADEMIC_SCHEME_IMMUTABLE');

        // AT-D02-09 & AT-D02-10: Class/stage and group labels support Bangla/English; duplicate code or stage rejected
        $clsRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/classes', [
                'program_id' => $programId,
                'code' => 'HIFZ-S1',
                'name_bn' => 'হিফয ১ম পর্ব',
                'name_en' => 'Hifz Stage 1',
                'stage_number' => 1,
            ]);
        $clsRes->assertStatus(201)
            ->assertJsonPath('data.name_bn', 'হিফয ১ম পর্ব')
            ->assertJsonPath('data.name_en', 'Hifz Stage 1');

        // Duplicate code rejected
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/classes', [
                'program_id' => $programId,
                'code' => 'HIFZ-S1',
                'name_bn' => 'হিফয ২য় পর্ব',
                'name_en' => 'Hifz Stage 2',
                'stage_number' => 2,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        // Duplicate stage_number rejected
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/classes', [
                'program_id' => $programId,
                'code' => 'HIFZ-S1-ALT',
                'name_bn' => 'হিফয বিকল্প ১ম পর্ব',
                'name_en' => 'Hifz Alt Stage 1',
                'stage_number' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        // Track creation with Bangla/English labels
        $trackRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/tracks', [
                'program_id' => $programId,
                'code' => 'GEN',
                'name_bn' => 'সাধারণ শাখা',
                'name_en' => 'General Track',
            ]);
        $trackRes->assertStatus(201)
            ->assertJsonPath('data.name_bn', 'সাধারণ শাখা')
            ->assertJsonPath('data.name_en', 'General Track');
    }

    /**
     * AT-D02-11 through AT-D02-18: Calendar & Session Cohorts, Explicit Period Dates, Overlap Rules
     */
    public function test_at_d02_11_to_18_cohorts_and_periods_date_and_overlap_rules(): void
    {
        $programs = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/academics/programs')
            ->json('data');

        $calendarProgram = collect($programs)->firstWhere('code', 'EBT');
        $sessionProgram = collect($programs)->firstWhere('code', 'ALM');
        $otherSessionProgram = collect($programs)->firstWhere('code', 'FZ-P');

        // AT-D02-11: CALENDAR_YEAR program can create 2026 with explicit dates and no cohort
        $calPeriod = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/periods', [
                'program_id' => $calendarProgram['id'],
                'cohort_id' => null,
                'label' => '2026',
                'calendar_year' => 2026,
                'study_year_number' => null,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ]);
        $calPeriod->assertStatus(201)
            ->assertJsonPath('data.calendar_year', 2026)
            ->assertJsonPath('data.cohort_id', null);

        // AT-D02-12: Calendar year program rejects cohort creation (422 INVALID_COHORT_SCHEME)
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/cohorts', [
                'program_id' => $calendarProgram['id'],
                'label' => '2026-27',
                'intake_year' => 2026,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_COHORT_SCHEME');

        // AT-D02-13: SESSION_BASED program creates cohort but does NOT infer dates
        $cohortRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/cohorts', [
                'program_id' => $sessionProgram['id'],
                'label' => '2027-28',
                'intake_year' => 2027,
            ]);
        $cohortRes->assertStatus(201)
            ->assertJsonPath('data.start_date', null)
            ->assertJsonPath('data.end_date', null);
        $cohort2728Id = $cohortRes->json('data.id');

        // AT-D02-14: Session period requires a valid cohort and study_year_number
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/periods', [
                'program_id' => $sessionProgram['id'],
                'cohort_id' => null,
                'label' => 'Missing Cohort Period',
                'start_date' => '2027-07-01',
                'end_date' => '2028-06-30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // AT-D02-15: Session period from different program cohort fails
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/periods', [
                'program_id' => $otherSessionProgram['id'],
                'cohort_id' => $cohort2728Id,
                'label' => 'Mismatched Cohort Period',
                'study_year_number' => 1,
                'start_date' => '2027-07-01',
                'end_date' => '2028-06-30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PROGRAM_RELATION_MISMATCH');

        // Create Stage 1 period for cohort 2027-28 (overlapping in real dates with 2026-27 cohort!)
        // AT-D02-17: Two cohorts can have overlapping date ranges
        $stage1Res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/periods', [
                'program_id' => $sessionProgram['id'],
                'cohort_id' => $cohort2728Id,
                'label' => 'Alim 2027-28 First Year',
                'study_year_number' => 1,
                'start_date' => '2026-10-01',
                'end_date' => '2027-09-30',
            ]);
        $stage1Res->assertStatus(201);

        // AT-D02-16: Same cohort duplicate study-year stage fails
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/periods', [
                'program_id' => $sessionProgram['id'],
                'cohort_id' => $cohort2728Id,
                'label' => 'Duplicate Stage 1',
                'study_year_number' => 1,
                'start_date' => '2027-10-01',
                'end_date' => '2028-09-30',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        // AT-D02-18: Within one cohort overlapping stage periods are rejected (409 PERIOD_DATE_CONFLICT)
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/periods', [
                'program_id' => $sessionProgram['id'],
                'cohort_id' => $cohort2728Id,
                'label' => 'Overlapping Stage 2',
                'study_year_number' => 2,
                'start_date' => '2027-05-01',
                'end_date' => '2028-04-30',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PERIOD_DATE_CONFLICT');
    }

    /**
     * AT-D02-19 through AT-D02-30, AT-D02-35, AT-D02-36: Offerings, Sections, Lifecycle, Optimistic Locking & Boundaries
     */
    public function test_at_d02_19_to_30_35_36_offerings_sections_and_period_lifecycle(): void
    {
        $programs = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/academics/programs')
            ->json('data');

        $almProgram = collect($programs)->firstWhere('code', 'ALM');
        $dkhProgram = collect($programs)->firstWhere('code', 'DKH');

        $almStructure = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/academics/programs/{$almProgram['id']}/structure")
            ->json('data');
        $dkhStructure = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/academics/programs/{$dkhProgram['id']}/structure")
            ->json('data');

        $almStage1Class = collect($almStructure['classes'])->firstWhere('stage_number', 1);
        $almStage2Class = collect($almStructure['classes'])->firstWhere('stage_number', 2);
        $dkhStage1Class = collect($dkhStructure['classes'])->firstWhere('stage_number', 1);

        // Find the DRAFT Stage 2 period of Alim 2026-27
        $draftStage2Period = collect($almStructure['periods'])->firstWhere('study_year_number', 2);

        // AT-D02-24: Period cannot OPEN until valid active offering/section exists (409 PERIOD_NOT_READY)
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/periods/{$draftStage2Period['id']}/status", [
                'state' => 'OPEN',
                'lock_version' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PERIOD_NOT_READY');

        // AT-D02-19: Session offering with different class stage is rejected (422 PERIOD_STAGE_MISMATCH)
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/offerings', [
                'academic_period_id' => $draftStage2Period['id'],
                'program_class_id' => $almStage1Class['id'],
                'academic_track_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERIOD_STAGE_MISMATCH');

        // AT-D02-20: Class/track from a different program is rejected (422 PROGRAM_RELATION_MISMATCH)
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/offerings', [
                'academic_period_id' => $draftStage2Period['id'],
                'program_class_id' => $dkhStage1Class['id'],
                'academic_track_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PROGRAM_RELATION_MISMATCH');

        // AT-D02-21: Offerings with null track remain unique
        $nullTrackOff = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/offerings', [
                'academic_period_id' => $draftStage2Period['id'],
                'program_class_id' => $almStage2Class['id'],
                'academic_track_id' => null,
            ]);
        $nullTrackOff->assertStatus(201);
        $offeringId = $nullTrackOff->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/academics/offerings', [
                'academic_period_id' => $draftStage2Period['id'],
                'program_class_id' => $almStage2Class['id'],
                'academic_track_id' => null,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        // AT-D02-23: Section capacity must be positive; negative and zero rejected
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/academics/offerings/{$offeringId}/sections", [
                'code' => 'A',
                'name_bn' => 'ক শাখা',
                'name_en' => 'Section A',
                'capacity' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // AT-D02-22: Section code unique inside offering
        $secRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/academics/offerings/{$offeringId}/sections", [
                'code' => 'A',
                'name_bn' => 'ক শাখা',
                'name_en' => 'Section A',
                'capacity' => 40,
            ]);
        $secRes->assertStatus(201)->assertJsonPath('data.capacity', 40);
        $sectionId = $secRes->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/academics/offerings/{$offeringId}/sections", [
                'code' => 'A',
                'name_bn' => 'ক শাখা ২',
                'name_en' => 'Section A 2',
                'capacity' => 35,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ACADEMIC_CODE');

        // Now period has a valid active offering + active section -> can transition DRAFT -> OPEN
        $openRes = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/periods/{$draftStage2Period['id']}/status", [
                'state' => 'OPEN',
                'lock_version' => 1,
            ]);
        $openRes->assertOk()
            ->assertJsonPath('data.state', 'OPEN')
            ->assertJsonPath('data.lock_version', 2);

        // AT-D02-26: Closing requires reason and records audit event
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/periods/{$draftStage2Period['id']}/status", [
                'state' => 'CLOSED',
                'lock_version' => 2,
                'reason' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $closeRes = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/periods/{$draftStage2Period['id']}/status", [
                'state' => 'CLOSED',
                'lock_version' => 2,
                'reason' => 'বার্ষিক পরীক্ষা ও ফলাফল চূড়ান্ত হয়েছে।',
            ]);
        $closeRes->assertOk()
            ->assertJsonPath('data.state', 'CLOSED')
            ->assertJsonPath('data.lock_version', 3);

        $this->assertTrue(
            AuditLog::where('entity_type', 'AcademicPeriod')
                ->where('entity_id', $draftStage2Period['id'])
                ->where('action_code', 'PERIOD_CLOSED')
                ->where('reason', 'বার্ষিক পরীক্ষা ও ফলাফল চূড়ান্ত হয়েছে।')
                ->exists()
        );

        // AT-D02-27: Concurrent stale close/reopen returns 409 VERSION_CONFLICT
        $staleRes = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/periods/{$draftStage2Period['id']}/status", [
                'state' => 'OPEN',
                'lock_version' => 2,
                'reason' => 'Stale version attempt',
            ]);
        $staleRes->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            // AT-D02-30: API errors retain D01 code/message/details/request_id shape
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        // AT-D02-25: CLOSED period cannot be silently edited through period or nested section route (409 CLOSED_PERIOD)
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/periods/{$draftStage2Period['id']}", [
                'label' => 'Illegal Rename',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/sections/{$sectionId}", [
                'capacity' => 99,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        // AT-D02-35: Period query retains historical CLOSED references for authorized read
        $closedList = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/academics/periods?program_id={$almProgram['id']}&state=CLOSED")
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($closedList);

        // AT-D02-28: Referenced authority/department/class cannot be deactivated while active dependents exist
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/academics/departments/{$almProgram['department_id']}/status", [
                'is_active' => false,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACTIVE_DEPENDENCY_EXISTS');

        // AT-D02-36: Student/payment/admission tables are not introduced in D02
        $this->assertFalse(Schema::hasTable('students'));
        $this->assertFalse(Schema::hasTable('admissions'));
        $this->assertFalse(Schema::hasTable('payment_slips'));
        $this->assertFalse(Schema::hasTable('invoices'));
    }
}

