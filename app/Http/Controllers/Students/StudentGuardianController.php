<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\AddStudentGuardianRequest;
use App\Http\Requests\Students\UpdateSmsConsentRequest;
use App\Http\Resources\Students\GuardianResource;
use App\Models\Student;
use App\Services\Students\GuardianAssociationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentGuardianController extends Controller
{
    public function __construct(
        protected GuardianAssociationService $guardianAssociationService
    ) {}

    public function index(int $studentId): JsonResponse
    {
        $student = Student::with('guardians')->findOrFail($studentId);

        return response()->json([
            'data' => GuardianResource::collection($student->guardians),
        ]);
    }

    public function store(AddStudentGuardianRequest $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $link = $this->guardianAssociationService->addGuardian(
            student: $student,
            data: $request->validated(),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        $guardian = $link->guardian;
        $guardian->setRelation('pivot', $link);

        return response()->json([
            'data' => new GuardianResource($guardian),
        ], 201);
    }

    public function makePrimary(Request $request, int $studentId, int $guardianId): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $link = $this->guardianAssociationService->makePrimary(
            student: $student,
            guardianId: $guardianId,
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        $guardian = $link->guardian;
        $guardian->setRelation('pivot', $link);

        return response()->json([
            'data' => new GuardianResource($guardian),
        ]);
    }

    public function updateConsent(UpdateSmsConsentRequest $request, int $studentId, int $guardianId): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $link = $this->guardianAssociationService->updateConsent(
            student: $student,
            guardianId: $guardianId,
            smsConsent: (bool) $request->input('sms_consent'),
            source: $request->input('consent_source'),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        $guardian = $link->guardian;
        $guardian->setRelation('pivot', $link);

        return response()->json([
            'data' => new GuardianResource($guardian),
        ]);
    }

    public function destroy(Request $request, int $studentId, int $guardianId): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $this->guardianAssociationService->removeGuardian(
            student: $student,
            guardianId: $guardianId,
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => ['success' => true, 'message' => 'Guardian unlinked successfully.'],
        ]);
    }
}

