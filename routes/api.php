<?php

use App\Http\Controllers\Academics\AcademicCohortController;
use App\Http\Controllers\Academics\AcademicOfferingController;
use App\Http\Controllers\Academics\AcademicOverviewController;
use App\Http\Controllers\Academics\AcademicPeriodController;
use App\Http\Controllers\Academics\AcademicProgramController;
use App\Http\Controllers\Academics\AcademicTrackController;
use App\Http\Controllers\Academics\AuthorityController;
use App\Http\Controllers\Academics\DepartmentController;
use App\Http\Controllers\Academics\EducationLevelController;
use App\Http\Controllers\Academics\InstitutionController;
use App\Http\Controllers\Academics\OfferingSectionController;
use App\Http\Controllers\Academics\ProgramClassController;
use App\Http\Controllers\Students\StudentController;
use App\Http\Controllers\Students\StudentDocumentController;
use App\Http\Controllers\Students\StudentDuplicateCheckController;
use App\Http\Controllers\Students\StudentDuplicateReviewController;
use App\Http\Controllers\Students\StudentFinancialLookupController;
use App\Http\Controllers\Students\StudentGuardianController;
use App\Http\Controllers\Students\StudentIdentifierController;
use App\Http\Controllers\Students\StudentRegistrationController;
use App\Http\Controllers\Admissions\AdmissionsWorkbenchController;
use App\Http\Controllers\Admissions\AdmissionsStudentSearchController;
use App\Http\Controllers\Admissions\AdmissionsEligibilityController;
use App\Http\Controllers\Admissions\AdmissionsCatalogController;
use App\Http\Controllers\Admissions\SectionCapacityController;
use App\Http\Controllers\Admissions\EnrollmentController;
use App\Http\Controllers\Admissions\ReturningStudentController;
use App\Http\Controllers\Admissions\SessionProgressionController;
use App\Http\Controllers\Admissions\EnrollmentActionController;
use App\Http\Controllers\Admissions\EnrollmentEventController;
use App\Http\Controllers\Authentication\ActivationController;
use App\Http\Controllers\RolePermission\RolePermissionController;
use App\Http\Controllers\UserManagement\UserController;
use App\Http\Controllers\UserProfile\ProfileController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints
Route::prefix('v1/auth')->group(function () {
    Route::post('/activate', [ActivationController::class, 'activate']);
});

// Authenticated session & self-service profile endpoints
Route::middleware(['auth:sanctum', 'active', 'locale'])->group(function () {
    Route::prefix('v1/me')->group(function () {
        Route::get('/', [ProfileController::class, 'me'])->middleware('permission:profile.read');
        Route::patch('/', [ProfileController::class, 'update'])->middleware('permission:profile.update');
        Route::put('/password', [ProfileController::class, 'changePassword'])->middleware('permission:password.change');
    });

    // D02: Read endpoints for permitted staff, accounts, and admin
    Route::prefix('v1/academics')->group(function () {
        Route::get('/institution', [InstitutionController::class, 'show'])->middleware('permission:institution.read');

        Route::middleware('permission:academics.read')->group(function () {
            Route::get('/summary', [AcademicOverviewController::class, 'summary']);
            Route::get('/hierarchy', [AcademicOverviewController::class, 'hierarchy']);
            Route::get('/options', [AcademicOverviewController::class, 'options']);

            Route::get('/authorities', [AuthorityController::class, 'index']);
            Route::get('/authorities/{id}', [AuthorityController::class, 'show'])->whereNumber('id');
            Route::get('/levels', [EducationLevelController::class, 'index']);
            Route::get('/levels/{id}', [EducationLevelController::class, 'show'])->whereNumber('id');

            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::get('/departments/{id}', [DepartmentController::class, 'show'])->whereNumber('id');

            Route::get('/programs', [AcademicProgramController::class, 'index']);
            Route::get('/programs/{id}', [AcademicProgramController::class, 'show'])->whereNumber('id');
            Route::get('/programs/{id}/structure', [AcademicProgramController::class, 'structure'])->whereNumber('id');

            Route::get('/classes', [ProgramClassController::class, 'index']);
            Route::get('/classes/{id}', [ProgramClassController::class, 'show'])->whereNumber('id');

            Route::get('/tracks', [AcademicTrackController::class, 'index']);
            Route::get('/tracks/{id}', [AcademicTrackController::class, 'show'])->whereNumber('id');

            Route::get('/cohorts', [AcademicCohortController::class, 'index']);
            Route::get('/cohorts/{id}', [AcademicCohortController::class, 'show'])->whereNumber('id');

            Route::get('/periods', [AcademicPeriodController::class, 'index']);
            Route::get('/periods/{id}', [AcademicPeriodController::class, 'show'])->whereNumber('id');

            Route::get('/offerings', [AcademicOfferingController::class, 'index']);
            Route::get('/offerings/{id}', [AcademicOfferingController::class, 'show'])->whereNumber('id');
            Route::get('/sections/{id}', [OfferingSectionController::class, 'show'])->whereNumber('id');
        });
    });

    // D03: Student & Guardian routes
    Route::prefix('v1/students')->group(function () {
        Route::post('/check-duplicate', [StudentDuplicateCheckController::class, 'check'])->middleware('permission:students.create');
        Route::post('/duplicate-reviews', [StudentDuplicateReviewController::class, 'store'])->middleware('permission:students.create');

        Route::post('/', [StudentRegistrationController::class, 'store'])->middleware('permission:students.create');
        Route::get('/lookup', [StudentFinancialLookupController::class, 'lookup'])->middleware('permission:students.financial_lookup');
        Route::get('/', [StudentController::class, 'index'])->middleware('permission:students.read');
        Route::get('/{id}', [StudentController::class, 'show'])->whereNumber('id')->middleware('permission:students.read');
        Route::patch('/{id}', [StudentController::class, 'update'])->whereNumber('id')->middleware('permission:students.update');
        Route::patch('/{id}/status', [StudentController::class, 'updateStatus'])->whereNumber('id')->middleware('permission:students.manage_status');

        // Guardians sub-resource
        Route::get('/{id}/guardians', [StudentGuardianController::class, 'index'])->whereNumber('id')->middleware('permission:students.read');
        Route::post('/{id}/guardians', [StudentGuardianController::class, 'store'])->whereNumber('id')->middleware('permission:students.manage_guardians');
        Route::post('/{id}/guardians/{guardianId}/primary', [StudentGuardianController::class, 'makePrimary'])->whereNumber(['id', 'guardianId'])->middleware('permission:students.manage_guardians');
        Route::patch('/{id}/guardians/{guardianId}/consent', [StudentGuardianController::class, 'updateConsent'])->whereNumber(['id', 'guardianId'])->middleware('permission:students.manage_guardians');
        Route::delete('/{id}/guardians/{guardianId}', [StudentGuardianController::class, 'destroy'])->whereNumber(['id', 'guardianId'])->middleware('permission:students.manage_guardians');

        // Identifiers sub-resource
        Route::get('/{id}/identifiers', [StudentIdentifierController::class, 'index'])->whereNumber('id')->middleware('permission:students.read');
        Route::post('/{id}/identifiers', [StudentIdentifierController::class, 'store'])->whereNumber('id')->middleware('permission:students.manage_identifiers');
        Route::delete('/{id}/identifiers/{identifierId}', [StudentIdentifierController::class, 'destroy'])->whereNumber(['id', 'identifierId'])->middleware('permission:students.manage_identifiers');

        // Documents sub-resource
        Route::get('/{id}/documents', [StudentDocumentController::class, 'index'])->whereNumber('id')->middleware('permission:students.read');
        Route::post('/{id}/documents', [StudentDocumentController::class, 'store'])->whereNumber('id')->middleware('permission:students.manage_documents');
        Route::get('/{id}/documents/{docId}/download', [StudentDocumentController::class, 'download'])->whereNumber(['id', 'docId'])->middleware('permission:students.read');
        Route::post('/{id}/documents/{docId}/verify', [StudentDocumentController::class, 'verify'])->whereNumber(['id', 'docId'])->middleware('permission:students.manage_documents');
        Route::delete('/{id}/documents/{docId}', [StudentDocumentController::class, 'destroy'])->whereNumber(['id', 'docId'])->middleware('permission:students.manage_documents');
    });

    // Admin user management, role administration & D02 academic mutations
    Route::prefix('v1/admin')->group(function () {
        // D01: User & Role management
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:users.read');
        Route::patch('/users/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::patch('/users/{id}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.disable');
        Route::put('/users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_role');
        Route::put('/users/{id}/permissions', [UserController::class, 'assignPermissions'])->middleware('permission:users.assign_role');
        Route::post('/users/{id}/invitations', [UserController::class, 'reissueInvitation'])->middleware('permission:users.update');

        Route::get('/roles', [RolePermissionController::class, 'roles'])->middleware('permission:roles.read');
        Route::post('/roles', [RolePermissionController::class, 'storeRole'])->middleware('permission:roles.read');
        Route::get('/permissions', [RolePermissionController::class, 'permissions'])->middleware('permission:roles.read');
        Route::post('/permissions', [RolePermissionController::class, 'storePermission'])->middleware('permission:roles.read');

        // D03: Admin duplicate review queue
        Route::get('/student-duplicates', [StudentDuplicateReviewController::class, 'index'])->middleware('permission:students.review_duplicates');
        Route::get('/student-duplicates/{id}', [StudentDuplicateReviewController::class, 'show'])->whereNumber('id')->middleware('permission:students.review_duplicates');
        Route::post('/student-duplicates/{id}/decision', [StudentDuplicateReviewController::class, 'decide'])->whereNumber('id')->middleware('permission:students.review_duplicates');

        // D02: Institution profile & branding mutations
        Route::patch('/institution', [InstitutionController::class, 'update'])->middleware('permission:institution.update');
        Route::post('/institution/logo', [InstitutionController::class, 'uploadLogo'])->middleware('permission:institution.update');

        // D02: Academic structure mutations
        Route::prefix('academics')->group(function () {
            Route::middleware('permission:academics.manage_structure')->group(function () {
                Route::post('/authorities', [AuthorityController::class, 'store']);
                Route::patch('/authorities/{id}', [AuthorityController::class, 'update'])->whereNumber('id');
                Route::patch('/authorities/{id}/status', [AuthorityController::class, 'updateStatus'])->whereNumber('id');

                Route::post('/levels', [EducationLevelController::class, 'store']);
                Route::patch('/levels/{id}', [EducationLevelController::class, 'update'])->whereNumber('id');
                Route::patch('/levels/{id}/status', [EducationLevelController::class, 'updateStatus'])->whereNumber('id');

                Route::post('/departments', [DepartmentController::class, 'store']);
                Route::patch('/departments/{id}', [DepartmentController::class, 'update'])->whereNumber('id');
                Route::patch('/departments/{id}/status', [DepartmentController::class, 'updateStatus'])->whereNumber('id');

                Route::post('/programs', [AcademicProgramController::class, 'store']);
                Route::patch('/programs/{id}', [AcademicProgramController::class, 'update'])->whereNumber('id');
                Route::patch('/programs/{id}/status', [AcademicProgramController::class, 'updateStatus'])->whereNumber('id');

                Route::post('/classes', [ProgramClassController::class, 'store']);
                Route::patch('/classes/{id}', [ProgramClassController::class, 'update'])->whereNumber('id');
                Route::patch('/classes/{id}/status', [ProgramClassController::class, 'updateStatus'])->whereNumber('id');

                Route::post('/tracks', [AcademicTrackController::class, 'store']);
                Route::patch('/tracks/{id}', [AcademicTrackController::class, 'update'])->whereNumber('id');
                Route::patch('/tracks/{id}/status', [AcademicTrackController::class, 'updateStatus'])->whereNumber('id');
            });

            Route::middleware('permission:academics.manage_cohorts')->group(function () {
                Route::post('/cohorts', [AcademicCohortController::class, 'store']);
                Route::patch('/cohorts/{id}', [AcademicCohortController::class, 'update'])->whereNumber('id');
                Route::patch('/cohorts/{id}/status', [AcademicCohortController::class, 'updateStatus'])->whereNumber('id');
            });

            Route::middleware('permission:academics.manage_periods')->group(function () {
                Route::post('/periods', [AcademicPeriodController::class, 'store']);
                Route::patch('/periods/{id}', [AcademicPeriodController::class, 'update'])->whereNumber('id');
            });

            Route::patch('/periods/{id}/status', [AcademicPeriodController::class, 'updateStatus'])
                ->whereNumber('id')
                ->middleware('permission:academics.close_periods');

            Route::middleware('permission:academics.manage_offerings')->group(function () {
                Route::post('/offerings', [AcademicOfferingController::class, 'store']);
                Route::patch('/offerings/{id}', [AcademicOfferingController::class, 'update'])->whereNumber('id');
                Route::post('/offerings/{id}/sections', [OfferingSectionController::class, 'store'])->whereNumber('id');
                Route::patch('/sections/{id}', [OfferingSectionController::class, 'update'])->whereNumber('id');
                Route::patch('/sections/{id}/status', [OfferingSectionController::class, 'updateStatus'])->whereNumber('id');
            });
        });
    });

    // D04: Admissions, Re-Admission & Enrollment routes
    Route::prefix('v1/admissions')->group(function () {
        Route::middleware('permission:admissions.read')->group(function () {
            Route::get('/workbench', [AdmissionsWorkbenchController::class, 'index']);
            Route::get('/search-students', [AdmissionsStudentSearchController::class, 'index']);
            Route::get('/students/{id}/eligibility', [AdmissionsEligibilityController::class, 'show'])->whereNumber('id');
            Route::get('/programs/{id}/catalogs', [AdmissionsCatalogController::class, 'show'])->whereNumber('id');
            Route::get('/sections/{id}/capacity', [SectionCapacityController::class, 'show'])->whereNumber('id');
            Route::get('/offerings/{id}/alternatives', [SectionCapacityController::class, 'alternatives'])->whereNumber('id');
            Route::post('/enrollments/batch-capacity-check', [SectionCapacityController::class, 'batchCheck']);
            Route::get('/students/{id}/returning-context', [ReturningStudentController::class, 'context'])->whereNumber('id');
            Route::get('/students/{id}/session-progression', [SessionProgressionController::class, 'show'])->whereNumber('id');
            Route::get('/enrollments/{id}', [EnrollmentController::class, 'show'])->whereNumber('id');
            Route::get('/enrollments/{id}/events', [EnrollmentEventController::class, 'index'])->whereNumber('id');
        });

        Route::middleware('permission:admissions.create')->group(function () {
            Route::post('/enrollments', [EnrollmentController::class, 'store']);
            Route::post('/enrollments/returning', [ReturningStudentController::class, 'store']);
            Route::post('/enrollments/session-progression', [SessionProgressionController::class, 'store']);
        });

        Route::post('/enrollments/{id}/reallocate', [EnrollmentActionController::class, 'reallocate'])
            ->whereNumber('id')
            ->middleware('permission:admissions.reallocate');

        Route::post('/enrollments/{id}/cancel', [EnrollmentActionController::class, 'cancel'])
            ->whereNumber('id')
            ->middleware('permission:admissions.cancel');

        Route::post('/enrollments/{id}/reject', [EnrollmentActionController::class, 'reject'])
            ->whereNumber('id')
            ->middleware('permission:admissions.cancel');
    });
});
