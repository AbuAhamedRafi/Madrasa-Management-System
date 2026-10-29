<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Services\Students\DuplicateDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDuplicateCheckController extends Controller
{
    public function __construct(
        protected DuplicateDetectionService $duplicateDetectionService
    ) {}

    public function check(Request $request): JsonResponse
    {
        $result = $this->duplicateDetectionService->check($request->all());

        return response()->json([
            'data' => $result,
        ]);
    }
}

