<?php

namespace App\Http\Controllers\Academics;

use App\Enums\ProgramState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicProgramRequest;
use App\Http\Requests\Academics\UpdateAcademicProgramRequest;
use App\Http\Requests\Academics\UpdateAcademicProgramStatusRequest;
use App\Http\Resources\Academics\AcademicProgramResource;
use App\Http\Resources\Academics\AcademicTrackResource;
use App\Http\Resources\Academics\ProgramClassResource;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Services\Academics\AcademicProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicProgramController extends Controller
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected AcademicProgramService $programService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->programRepository->paginate(
            $departmentId,
            $request->query('scheme'),
            $active,
            $request->query('state'),
            $request->query('search'),
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20)
        );

        return response()->json([
            'data' => AcademicProgramResource::collection($paginator->items()),
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
        $program = $this->programRepository->findById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        return response()->json([
            'data' => new AcademicProgramResource($program),
        ]);
    }

    public function structure(int $id): JsonResponse
    {
        $program = $this->programRepository->findStructureById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        return response()->json([
            'data' => array_merge(
                (new AcademicProgramResource($program))->resolve(),
                [
                    'has_dependencies' => $this->programRepository->hasDependentCohortsOrPeriods($program->id),
                    'classes' => ProgramClassResource::collection($program->classes)->resolve(),
                    'tracks' => AcademicTrackResource::collection($program->tracks)->resolve(),
                    'cohorts' => $program->cohorts->map(fn ($ch) => [
                        'id' => $ch->id,
                        'label' => $ch->label,
                        'intake_year' => $ch->intake_year,
                        'status' => $ch->status->value,
                    ])->values(),
                    'periods' => $program->periods->map(fn ($pd) => [
                        'id' => $pd->id,
                        'cohort_id' => $pd->cohort_id,
                        'label' => $pd->label,
                        'calendar_year' => $pd->calendar_year,
                        'study_year_number' => $pd->study_year_number,
                        'start_date' => $pd->start_date->format('Y-m-d'),
                        'end_date' => $pd->end_date->format('Y-m-d'),
                        'state' => $pd->state->value,
                    ])->values(),
                ]
            ),
        ]);
    }

    public function store(StoreAcademicProgramRequest $request): JsonResponse
    {
        $program = $this->programService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicProgramResource($program),
        ], 201);
    }

    public function update(UpdateAcademicProgramRequest $request, int $id): JsonResponse
    {
        $program = $this->programRepository->findById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $updated = $this->programService->update(
            $program,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicProgramResource($updated),
        ]);
    }

    public function updateStatus(UpdateAcademicProgramStatusRequest $request, int $id): JsonResponse
    {
        $program = $this->programRepository->findById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $updated = $this->programService->updateStatus(
            $program,
            ProgramState::from($request->input('state')),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicProgramResource($updated),
        ]);
    }
}
