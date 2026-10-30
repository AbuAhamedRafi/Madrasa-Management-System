<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\CreateEnrollmentRequest;
use App\Models\Enrollment;
use App\Services\Admissions\EnrollmentPlacementService;
use App\Services\Admissions\SeatAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(
        protected EnrollmentPlacementService $placementService,
        protected SeatAllocationService $seatAllocationService
    ) {}

    public function store(CreateEnrollmentRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $actorId = (int) $request->user()->id;

        $enrollment = $this->placementService->createEnrollment(
            $request->validated(),
            $actorId,
            $idempotencyKey
        );

        return response()->json([
            'data' => $enrollment,
            'message' => 'Enrollment submitted successfully in PENDING_BILLING status.',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $enrollment = Enrollment::with([
            'student.primaryStudentGuardian.guardian',
            'student.identifiers',
            'programAdmission.program.department',
            'programAdmission.cohort',
            'period.program',
            'offering.programClass',
            'offering.track',
            'section',
            'statusEvents.actor',
            'creator',
        ])->findOrFail($id);

        $capacity = $this->seatAllocationService->checkCapacity($enrollment->offering_section_id);

        return response()->json([
            'data' => [
                'enrollment' => $enrollment,
                'section_capacity' => $capacity,
                'handoff' => [
                    'status' => $enrollment->status->value,
                    'billing_cleared' => $enrollment->billing_cleared,
                    'payment_cleared' => $enrollment->payment_cleared,
                    'next_action' => match ($enrollment->status->value) {
                        'PENDING_BILLING' => 'Awaiting fee assessment and billing preparation by Accounts office (D06).',
                        'PENDING_PAYMENT' => 'Awaiting fee collection and cash slip settlement (D07/D08).',
                        'APPROVED' => 'Student enrollment is fully active and cleared.',
                        default => 'Enrollment reached terminal state: ' . $enrollment->status->value,
                    },
                ],
            ],
        ]);
    }
}
