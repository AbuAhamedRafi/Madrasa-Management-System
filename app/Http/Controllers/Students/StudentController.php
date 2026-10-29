<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\UpdateStudentRequest;
use App\Http\Requests\Students\UpdateStudentStatusRequest;
use App\Http\Resources\Students\StudentResource;
use App\Repositories\AuditLogRepository;
use App\Repositories\Students\StudentRepository;
use App\Services\Students\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(
        protected StudentRepository $studentRepository,
        protected StudentService $studentService,
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'query' => $request->query('query'),
            'status' => $request->query('status'),
            'admission_year' => $request->query('admission_year'),
            'gender' => $request->query('gender'),
        ];

        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);

        $paginator = $this->studentRepository->paginate($filters, $page, $pageSize);

        return response()->json([
            'data' => StudentResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $student = $this->studentRepository->findById($id);
        if (!$student) {
            abort(404, 'Student not found.');
        }

        $auditHistory = $this->auditLogRepository->getEntityHistory('Student', $student->id)->map(fn ($log) => [
            'id' => $log->id,
            'action_code' => $log->action_code,
            'reason' => $log->reason,
            'before_metadata' => $log->before_metadata,
            'after_metadata' => $log->after_metadata,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'username' => $log->actor->username,
                'name_bn' => $log->actor->name_bn,
                'name_en' => $log->actor->name_en,
            ] : null,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        $resourceData = (new StudentResource($student))->resolve();
        $resourceData['audit_history'] = $auditHistory;

        return response()->json([
            'data' => $resourceData,
        ]);
    }

    public function update(UpdateStudentRequest $request, int $id): JsonResponse
    {
        $student = $this->studentRepository->findById($id);
        if (!$student) {
            abort(404, 'Student not found.');
        }

        $updated = $this->studentService->updateProfile(
            student: $student,
            data: $request->validated(),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new StudentResource($updated),
        ]);
    }

    public function updateStatus(UpdateStudentStatusRequest $request, int $id): JsonResponse
    {
        $student = $this->studentRepository->findById($id);
        if (!$student) {
            abort(404, 'Student not found.');
        }

        $updated = $this->studentService->updateStatus(
            student: $student,
            newStatusStr: $request->input('status'),
            reason: $request->input('reason'),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new StudentResource($updated),
        ]);
    }
}
