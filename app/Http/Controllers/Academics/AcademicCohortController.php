<?php

namespace App\Http\Controllers\Academics;

use App\Enums\CohortStatus;
use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicCohortRequest;
use App\Http\Requests\Academics\UpdateAcademicCohortRequest;
use App\Http\Requests\Academics\UpdateAcademicCohortStatusRequest;
use App\Http\Resources\Academics\AcademicCohortResource;
use App\Repositories\Academics\AcademicCohortRepository;
use App\Services\Academics\AcademicCohortService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicCohortController extends Controller
{
    public function __construct(
        protected AcademicCohortRepository $cohortRepository,
        protected AcademicCohortService $cohortService
    ) {}

    public function index(Request $request): JsonResponse
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
            'data' => AcademicCohortResource::collection($paginator->items()),
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
        $cohort = $this->cohortRepository->findById($id);
        if (! $cohort) {
            abort(404, 'Academic cohort not found.');
        }

        return response()->json([
            'data' => new AcademicCohortResource($cohort),
        ]);
    }

    public function store(StoreAcademicCohortRequest $request): JsonResponse
    {
        $cohort = $this->cohortService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicCohortResource($cohort),
        ], 201);
    }

    public function update(UpdateAcademicCohortRequest $request, int $id): JsonResponse
    {
        $cohort = $this->cohortRepository->findById($id);
        if (! $cohort) {
            abort(404, 'Academic cohort not found.');
        }

        $updated = $this->cohortService->update(
            $cohort,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicCohortResource($updated),
        ]);
    }

    public function updateStatus(UpdateAcademicCohortStatusRequest $request, int $id): JsonResponse
    {
        $cohort = $this->cohortRepository->findById($id);
        if (! $cohort) {
            abort(404, 'Academic cohort not found.');
        }

        $updated = $this->cohortService->updateStatus(
            $cohort,
            CohortStatus::from($request->input('status')),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicCohortResource($updated),
        ]);
    }
}
