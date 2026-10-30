<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\CancelEnrollmentRequest;
use App\Http\Requests\Admissions\ReallocateSectionRequest;
use App\Models\Enrollment;
use App\Services\Admissions\EnrollmentPlacementService;
use App\Services\Admissions\SeatAllocationService;
use Illuminate\Http\JsonResponse;

class EnrollmentActionController extends Controller
{
    public function __construct(
        protected SeatAllocationService $seatAllocationService,
        protected EnrollmentPlacementService $placementService
    ) {}

    public function reallocate(ReallocateSectionRequest $request, int $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);
        $actorId = (int) $request->user()->id;

        $updated = $this->seatAllocationService->reallocateSeat(
            $enrollment,
            (int) $request->input('new_section_id'),
            $request->input('reason'),
            $actorId
        );

        return response()->json([
            'data' => $updated,
            'message' => 'Student section placement reallocated successfully.',
        ]);
    }

    public function cancel(CancelEnrollmentRequest $request, int $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);
        $actorId = (int) $request->user()->id;

        $updated = $this->placementService->cancelEnrollment(
            $enrollment,
            $request->input('reason'),
            $actorId
        );

        return response()->json([
            'data' => $updated,
            'message' => 'Enrollment cancelled successfully and section seat released.',
        ]);
    }

    public function reject(CancelEnrollmentRequest $request, int $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);
        $actorId = (int) $request->user()->id;

        $updated = $this->placementService->rejectEnrollment(
            $enrollment,
            $request->input('reason'),
            $actorId
        );

        return response()->json([
            'data' => $updated,
            'message' => 'Enrollment rejected successfully.',
        ]);
    }
}

