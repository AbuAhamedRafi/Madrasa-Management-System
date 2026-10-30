<?php

namespace App\Http\Controllers\Admissions;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmissionsWorkbenchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Enrollment::with([
            'student',
            'period.program',
            'offering.programClass',
            'offering.track',
            'section',
        ]);

        if ($request->filled('program_id')) {
            $query->whereHas('period', function ($q) use ($request) {
                $q->where('program_id', $request->query('program_id'));
            });
        }

        if ($request->filled('academic_period_id')) {
            $query->where('academic_period_id', $request->query('academic_period_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name_bn', 'like', "%{$search}%")
                    ->orWhere('name_en', 'ilike', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%");
            });
        }

        // Summary counts
        $counts = [
            'total' => Enrollment::count(),
            'pending_billing' => Enrollment::where('status', EnrollmentStatus::PENDING_BILLING->value)->count(),
            'pending_payment' => Enrollment::where('status', EnrollmentStatus::PENDING_PAYMENT->value)->count(),
            'approved' => Enrollment::where('status', EnrollmentStatus::APPROVED->value)->count(),
            'cancelled' => Enrollment::where('status', EnrollmentStatus::CANCELLED->value)->count(),
            'rejected' => Enrollment::where('status', EnrollmentStatus::REJECTED->value)->count(),
        ];

        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'counts' => $counts,
            ],
        ]);
    }
}
