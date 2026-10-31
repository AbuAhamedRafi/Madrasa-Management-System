<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\CreateStudentRequest;
use App\Services\Students\StudentCreationService;
use Illuminate\Http\JsonResponse;

class StudentRegistrationController extends Controller
{
    public function __construct(
        protected StudentCreationService $studentCreationService
    ) {}

    public function store(CreateStudentRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');
        $actorId = $request->user()->id;
        $ipAddress = $request->ip();
        $requestId = $request->attributes->get('request_id');

        $result = $this->studentCreationService->create(
            data: $request->validated(),
            actorId: $actorId,
            idempotencyKey: $idempotencyKey,
            ipAddress: $ipAddress,
            requestId: $requestId
        );

        $response = response()->json(['data' => $result['data']], $result['http_status']);

        if (!empty($result['is_replay'])) {
            $response->header('Idempotent-Replay', 'true');
        }

        return $response;
    }
}

