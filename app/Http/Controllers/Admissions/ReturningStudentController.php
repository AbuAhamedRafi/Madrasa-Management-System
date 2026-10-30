<?php

namespace App\Http\Controllers\Admissions;

use App\Enums\EnrollmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\ReturningEnrollmentRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Admissions\EnrollmentPlacementService;
use Illuminate\Http\JsonResponse;

class ReturningStudentController extends Controller
{
    public function __construct(
        protected EnrollmentPlacementService $placementService
    ) {}

    public function context(int $studentId): JsonResponse
    {
        $student = Student::with(['primaryStudentGuardian.guardian'])->findOrFail($studentId);

        $previousEnrollment = Enrollment::with([
            'period.program',
            'offering.programClass',
            'offering.track',
            'section',
        ])
            ->where('student_id', $studentId)
            ->orderByDesc('id')
            ->first();

        $programId = $previousEnrollment?->period?->program_id;
        $program = $programId ? AcademicProgram::with(['classes', 'tracks', 'periods'])->find($programId) : null;

        $availablePeriods = $program
            ? $program->periods->whereIn('state', ['OPEN', 'DRAFT'])->values()
            : [];

        $currentStage = $previousEnrollment?->offering?->programClass?->stage_number ?? 1;
        $nextStage = $currentStage + 1;

        return response()->json([
            'data' => [
                'student' => $student,
                'previous_enrollment' => $previousEnrollment,
                'current_stage' => $currentStage,
                'suggested_next_stage' => $nextStage,
                'program' => $program,
                'available_periods' => $availablePeriods,
                'classes' => $program?->classes ?? [],
                'tracks' => $program?->tracks ?? [],
            ],
        ]);
    }

    public function store(ReturningEnrollmentRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['enrollment_type'] = EnrollmentType::CONTINUING->value;

        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key');

        $enrollment = $this->placementService->createEnrollment($payload, $actorId, $idempotencyKey);

        return response()->json([
            'data' => $enrollment,
            'message' => 'Continuing student re-admission enrollment submitted successfully in PENDING_BILLING status.',
        ], 201);
    }
}
