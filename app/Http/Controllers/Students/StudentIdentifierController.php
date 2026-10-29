<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\StoreStudentIdentifierRequest;
use App\Http\Resources\Students\StudentIdentifierResource;
use App\Models\Student;
use App\Services\Students\StudentIdentifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentIdentifierController extends Controller
{
    public function __construct(
        protected StudentIdentifierService $studentIdentifierService
    ) {}

    public function index(int $studentId): JsonResponse
    {
        $student = Student::with('identifiers')->findOrFail($studentId);

        return response()->json([
            'data' => StudentIdentifierResource::collection($student->identifiers),
        ]);
    }

    public function store(StoreStudentIdentifierRequest $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $ident = $this->studentIdentifierService->addIdentifier(
            student: $student,
            data: $request->validated(),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new StudentIdentifierResource($ident),
        ], 201);
    }

    public function destroy(Request $request, int $studentId, int $id): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $this->studentIdentifierService->removeIdentifier(
            student: $student,
            identifierId: $id,
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => ['success' => true, 'message' => 'Identifier removed successfully.'],
        ]);
    }
}

