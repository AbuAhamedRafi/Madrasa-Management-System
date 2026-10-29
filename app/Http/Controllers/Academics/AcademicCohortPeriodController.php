<?php

namespace App\Http\Controllers\Academics;

use App\Enums\CohortStatus;
use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicCohortRequest;
use App\Http\Requests\Academics\StoreAcademicPeriodRequest;
use App\Http\Requests\Academics\UpdateAcademicCohortRequest;
use App\Http\Requests\Academics\UpdateAcademicCohortStatusRequest;
use App\Http\Requests\Academics\UpdateAcademicPeriodRequest;
use App\Http\Requests\Academics\UpdateAcademicPeriodStatusRequest;
use App\Models\AcademicCohort;
use App\Models\AcademicPeriod;
use App\Repositories\Academics\AcademicCohortRepository;
use App\Repositories\Academics\AcademicPeriodRepository;
use App\Repositories\AuditLogRepository;
use App\Services\Academics\AcademicCohortPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicCohortPeriodController extends Controller
{
    public function __construct(
        protected AcademicCohortRepository $cohortRepository,
        protected AcademicPeriodRepository $periodRepository,
        protected AcademicCohortPeriodService $cohortPeriodService,
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function indexCohorts(Request $request): JsonResponse
    {
        if (! $request->filled('program_id')) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'program_id query parameter is required.',
                422,
                ['program_id' => ['program_id query parameter is required.']]
            );
        }

        $intakeYear = $request->filled('intake_year') ? (int) $request->query('intake_year') : null;

        $paginator = $this->cohortRepository->paginate(
            (int) $request->query('program_id'),
            $request->query('status'),
            $intakeYear,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20)
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AcademicCohort $c) => $this->formatCohort($c))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showCohort(int $id): JsonResponse
    {
        $cohort = $this->cohortRepository->findById($id);
        if (! $cohort) {
            abort(404, 'Academic cohort not found.');
        }

        return response()->json([
            'data' => $this->formatCohort($cohort, true),
        ]);
    }

    public function storeCohort(StoreAcademicCohortRequest $request): JsonResponse
    {
        $cohort = $this->cohortPeriodService->createCohort(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatCohort($cohort),
        ], 201);
    }

    public function updateCohort(UpdateAcademicCohortRequest $request, int $id): JsonResponse
    {
        $cohort = $this->cohortRepository->findById($id);
        if (! $cohort) {
            abort(404, 'Academic cohort not found.');
        }

        $updated = $this->cohortPeriodService->updateCohort(
            $cohort,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatCohort($updated),
        ]);
    }

    public function updateCohortStatus(UpdateAcademicCohortStatusRequest $request, int $id): JsonResponse
    {
        $cohort = $this->cohortRepository->findById($id);
        if (! $cohort) {
            abort(404, 'Academic cohort not found.');
        }

        $updated = $this->cohortPeriodService->updateCohortStatus(
            $cohort,
            CohortStatus::from($request->input('status')),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatCohort($updated),
        ]);
    }

    public function indexPeriods(Request $request): JsonResponse
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
            'data' => collect($paginator->items())->map(fn (AcademicPeriod $p) => $this->formatPeriod($p))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showPeriod(int $id): JsonResponse
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
            'data' => $this->formatPeriod($period, true) + [
                'audit_history' => $auditHistory,
            ],
        ]);
    }

    public function storePeriod(StoreAcademicPeriodRequest $request): JsonResponse
    {
        $period = $this->cohortPeriodService->createPeriod(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatPeriod($period),
        ], 201);
    }

    public function updatePeriod(UpdateAcademicPeriodRequest $request, int $id): JsonResponse
    {
        $period = $this->periodRepository->findById($id);
        if (! $period) {
            abort(404, 'Academic period not found.');
        }

        $updated = $this->cohortPeriodService->updatePeriod(
            $period,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatPeriod($updated),
        ]);
    }

    public function updatePeriodStatus(UpdateAcademicPeriodStatusRequest $request, int $id): JsonResponse
    {
        $updated = $this->cohortPeriodService->updatePeriodStatus(
            $id,
            PeriodState::from($request->input('state')),
            (int) $request->input('lock_version'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatPeriod($updated),
        ]);
    }

    protected function formatCohort(AcademicCohort $c, bool $includePeriods = false): array
    {
        $data = [
            'id' => $c->id,
            'program_id' => $c->program_id,
            'label' => $c->label,
            'intake_year' => $c->intake_year,
            'description' => $c->description,
            'start_date' => $c->start_date?->format('Y-m-d'),
            'end_date' => $c->end_date?->format('Y-m-d'),
            'status' => $c->status->value,
            'periods_count' => $c->periods_count ?? $c->periods()->count(),
        ];

        if ($includePeriods && $c->relationLoaded('periods')) {
            $data['periods'] = $c->periods->map(fn ($p) => $this->formatPeriod($p))->values();
        }

        return $data;
    }

    protected function formatPeriod(AcademicPeriod $p, bool $includeOfferings = false): array
    {
        $data = [
            'id' => $p->id,
            'program_id' => $p->program_id,
            'cohort_id' => $p->cohort_id,
            'label' => $p->label,
            'calendar_year' => $p->calendar_year,
            'study_year_number' => $p->study_year_number,
            'start_date' => $p->start_date->format('Y-m-d'),
            'end_date' => $p->end_date->format('Y-m-d'),
            'state' => $p->state->value,
            'lock_version' => $p->lock_version,
            'updated_at' => $p->updated_at?->toISOString(),
            'program' => $p->relationLoaded('program') && $p->program ? [
                'id' => $p->program->id,
                'code' => $p->program->code,
                'name_bn' => $p->program->name_bn,
                'name_en' => $p->program->name_en,
                'scheme' => $p->program->scheme->value,
            ] : null,
            'cohort' => $p->relationLoaded('cohort') && $p->cohort ? [
                'id' => $p->cohort->id,
                'label' => $p->cohort->label,
                'intake_year' => $p->cohort->intake_year,
                'status' => $p->cohort->status->value,
            ] : null,
        ];

        if ($includeOfferings && $p->relationLoaded('offerings')) {
            $data['offerings'] = $p->offerings->map(fn ($o) => [
                'id' => $o->id,
                'program_class_id' => $o->program_class_id,
                'academic_track_id' => $o->academic_track_id,
                'class_name_bn' => $o->programClass?->name_bn,
                'class_name_en' => $o->programClass?->name_en,
                'track_name_bn' => $o->track?->name_bn,
                'track_name_en' => $o->track?->name_en,
                'is_active' => $o->is_active,
                'sections_count' => $o->sections->count(),
            ])->values();
        }

        return $data;
    }
}

