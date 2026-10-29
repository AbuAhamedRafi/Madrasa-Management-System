<?php

namespace App\Http\Controllers\Academics;

use App\Enums\PeriodState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicPeriodRequest;
use App\Http\Requests\Academics\UpdateAcademicPeriodRequest;
use App\Http\Requests\Academics\UpdateAcademicPeriodStatusRequest;
use App\Http\Resources\Academics\AcademicPeriodResource;
use App\Repositories\Academics\AcademicPeriodRepository;
use App\Repositories\AuditLogRepository;
use App\Services\Academics\AcademicPeriodService;
use App\Services\Academics\PeriodLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicPeriodController extends Controller
{
    public function __construct(
        protected AcademicPeriodRepository $periodRepository,
        protected AcademicPeriodService $periodService,
        protected PeriodLifecycleService $periodLifecycleService,
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $programId = $request->filled('program_id') ? (int) $request->query('program_id') : null;
        $cohortId = $request->filled('cohort_id') ? (int) $request->query('cohort_id') : null;

        $paginator = $this->periodRepository->paginate(
            $programId,
            $cohortId,
            $request->query('state'),
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20)
        );

        return response()->json([
            'data' => AcademicPeriodResource::collection($paginator->items()),
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
        $period = $this->periodRepository->findById($id);
        if (! $period) {
            abort(404, 'Academic period not found.');
        }

        $auditHistory = $this->auditLogRepository->getEntityHistory('AcademicPeriod', $period->id)->map(fn ($log) => [
            'id' => $log->id,
            'action_code' => $log->action_code,
            'actor_name' => $log->actor?->name_en ?? $log->actor?->username ?? 'System',
            'actor_name_bn' => $log->actor?->name_bn ?? $log->actor?->username ?? 'সিস্টেম',
            'before_metadata' => $log->before_metadata,
            'after_metadata' => $log->after_metadata,
            'reason' => $log->reason ?? ($log->after_metadata['reason'] ?? null),
            'created_at' => $log->created_at?->toISOString(),
        ])->values();

        return response()->json([
            'data' => array_merge(
                (new AcademicPeriodResource($period))->resolve(),
                [
                    'audit_history' => $auditHistory,
                ]
            ),
        ]);
    }

    public function store(StoreAcademicPeriodRequest $request): JsonResponse
    {
        $period = $this->periodService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicPeriodResource($period),
        ], 201);
    }

    public function update(UpdateAcademicPeriodRequest $request, int $id): JsonResponse
    {
        $period = $this->periodRepository->findById($id);
        if (! $period) {
            abort(404, 'Academic period not found.');
        }

        $updated = $this->periodService->update(
            $period,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicPeriodResource($updated),
        ]);
    }

    public function updateStatus(UpdateAcademicPeriodStatusRequest $request, int $id): JsonResponse
    {
        $updated = $this->periodLifecycleService->transitionState(
            $id,
            PeriodState::from($request->input('state')),
            (int) $request->input('lock_version'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicPeriodResource($updated),
        ]);
    }
}
