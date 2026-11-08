<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Services\Payments\AdmissionPaymentClearanceService;
use Illuminate\Http\JsonResponse;

class AdmissionPaymentClearanceController extends Controller
{
    public function __construct(
        protected AdmissionPaymentClearanceService $clearanceService
    ) {}

    public function show(Enrollment $enrollment): JsonResponse
    {
        $status = $this->clearanceService->getClearanceStatus($enrollment->id);

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'enrollment_id' => $enrollment->id,
                'enrollment_status' => $enrollment->status->value,
                'student_id' => $enrollment->student_id,
            ], $status),
        ]);
    }
}

