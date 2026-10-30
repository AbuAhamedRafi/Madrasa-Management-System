<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;

class EnrollmentEventController extends Controller
{
    public function index(int $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);

        $events = $enrollment->statusEvents()
            ->with('actor')
            ->orderBy('occurred_at', 'asc')
            ->get();

        return response()->json(['data' => $events]);
    }
}
