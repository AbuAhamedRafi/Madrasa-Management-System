<?php

namespace App\Http\Controllers\Admissions;

use App\Enums\AcademicScheme;
use App\Enums\EnrollmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\SessionProgressionRequest;
use App\Models\AcademicCohort;
use App\Models\AcademicProgram;
use App\Models\Enrollment;
use App\Models\ProgramAdmission;
use App\Models\Student;
use App\Services\Admissions\EnrollmentPlacementService;
use Illuminate\Http\JsonResponse;

class SessionProgressionController extends Controller
{
    public function __construct(
        protected EnrollmentPlacementService $placementService
    ) {}

    public function show(int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        // Find session-based admission
        $admission = ProgramAdmission::with(['program.cohorts', 'cohort.periods.offerings.programClass'])
            ->where('student_id', $studentId)
            ->whereHas('program', fn($q) => $q->where('scheme', AcademicScheme::SESSION_BASED->value))
            ->orderByDesc('id')
            ->first();

        if (!$admission || !$admission->cohort) {
            return response()->json([
                'data' => [
                    'student' => $student,
                    'has_session_program' => false,
                    'message' => 'No active session-based cohort admission found for this student.',
                ],
            ]);
        }

        $cohort = $admission->cohort;
        $periods = $cohort->periods()->orderBy('study_year_number')->with(['offerings.programClass', 'offerings.sections'])->get();

        // Get past enrollments for this student in this cohort's periods
        $pastEnrollments = Enrollment::where('student_id', $studentId)
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->with(['period', 'offering.programClass', 'section'])
            ->get();

        $enrolledPeriodIds = $pastEnrollments->pluck('academic_period_id')->toArray();
        $nextPeriod = $periods->first(fn($p) => !in_array($p->id, $enrolledPeriodIds) && $p->state->value !== 'CLOSED');

        return response()->json([
            'data' => [
                'student' => $student,
                'has_session_program' => true,
                'program' => $admission->program,
                'cohort' => $cohort,
                'periods_timeline' => $periods,
                'past_enrollments' => $pastEnrollments,
                'next_period' => $nextPeriod,
            ],
        ]);
    }

    public function store(SessionProgressionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['enrollment_type'] = EnrollmentType::CONTINUING->value;

        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key');

        $enrollment = $this->placementService->createEnrollment($payload, $actorId, $idempotencyKey);

        return response()->json([
            'data' => $enrollment,
            'message' => 'Session cohort progression enrollment submitted successfully in PENDING_BILLING status.',
        ], 201);
    }
}

