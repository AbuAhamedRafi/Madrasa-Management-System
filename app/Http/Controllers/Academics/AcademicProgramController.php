<?php

namespace App\Http\Controllers\Academics;

use App\Enums\ProgramState;
use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicProgramRequest;
use App\Http\Requests\Academics\StoreAcademicTrackRequest;
use App\Http\Requests\Academics\StoreProgramClassRequest;
use App\Http\Requests\Academics\UpdateAcademicProgramRequest;
use App\Http\Requests\Academics\UpdateAcademicProgramStatusRequest;
use App\Http\Requests\Academics\UpdateAcademicTrackRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateProgramClassRequest;
use App\Models\AcademicProgram;
use App\Models\AcademicTrack;
use App\Models\ProgramClass;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Repositories\Academics\AcademicTrackRepository;
use App\Repositories\Academics\ProgramClassRepository;
use App\Services\Academics\AcademicProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicProgramController extends Controller
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected ProgramClassRepository $classRepository,
        protected AcademicTrackRepository $trackRepository,
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
            'data' => collect($paginator->items())->map(fn (AcademicProgram $p) => $this->formatProgram($p))->values(),
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
            'data' => $this->formatProgram($program),
        ]);
    }

    public function structure(int $id): JsonResponse
    {
        $program = $this->programRepository->findStructureById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        return response()->json([
            'data' => $this->formatProgram($program) + [
                'has_dependencies' => $this->programRepository->hasDependentCohortsOrPeriods($program->id),
                'classes' => $program->classes->map(fn (ProgramClass $c) => $this->formatClass($c))->values(),
                'tracks' => $program->tracks->map(fn (AcademicTrack $t) => $this->formatTrack($t))->values(),
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
            ],
        ]);
    }

    public function store(StoreAcademicProgramRequest $request): JsonResponse
    {
        $program = $this->programService->createProgram(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatProgram($program),
        ], 201);
    }

    public function update(UpdateAcademicProgramRequest $request, int $id): JsonResponse
    {
        $program = $this->programRepository->findById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $updated = $this->programService->updateProgram(
            $program,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatProgram($updated),
        ]);
    }

    public function updateStatus(UpdateAcademicProgramStatusRequest $request, int $id): JsonResponse
    {
        $program = $this->programRepository->findById($id);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $updated = $this->programService->updateProgramStatus(
            $program,
            ProgramState::from($request->input('state')),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatProgram($updated),
        ]);
    }

    public function indexClasses(Request $request): JsonResponse
    {
        if (! $request->filled('program_id')) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'program_id query parameter is required.',
                422,
                ['program_id' => ['program_id query parameter is required.']]
            );
        }

        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->classRepository->paginate(
            (int) $request->query('program_id'),
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50)
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ProgramClass $c) => $this->formatClass($c))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showClass(int $id): JsonResponse
    {
        $class = $this->classRepository->findById($id);
        if (! $class) {
            abort(404, 'Program class not found.');
        }

        return response()->json([
            'data' => $this->formatClass($class),
        ]);
    }

    public function storeClass(StoreProgramClassRequest $request): JsonResponse
    {
        $class = $this->programService->createClass(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatClass($class),
        ], 201);
    }

    public function updateClass(UpdateProgramClassRequest $request, int $id): JsonResponse
    {
        $class = $this->classRepository->findById($id);
        if (! $class) {
            abort(404, 'Program class not found.');
        }

        $updated = $this->programService->updateClass(
            $class,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatClass($updated),
        ]);
    }

    public function updateClassStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $class = $this->classRepository->findById($id);
        if (! $class) {
            abort(404, 'Program class not found.');
        }

        $updated = $this->programService->updateClassStatus(
            $class,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatClass($updated),
        ]);
    }

    public function indexTracks(Request $request): JsonResponse
    {
        if (! $request->filled('program_id')) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'program_id query parameter is required.',
                422,
                ['program_id' => ['program_id query parameter is required.']]
            );
        }

        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->trackRepository->paginate(
            (int) $request->query('program_id'),
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50)
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AcademicTrack $t) => $this->formatTrack($t))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showTrack(int $id): JsonResponse
    {
        $track = $this->trackRepository->findById($id);
        if (! $track) {
            abort(404, 'Academic track not found.');
        }

        return response()->json([
            'data' => $this->formatTrack($track),
        ]);
    }

    public function storeTrack(StoreAcademicTrackRequest $request): JsonResponse
    {
        $track = $this->programService->createTrack(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatTrack($track),
        ], 201);
    }

    public function updateTrack(UpdateAcademicTrackRequest $request, int $id): JsonResponse
    {
        $track = $this->trackRepository->findById($id);
        if (! $track) {
            abort(404, 'Academic track not found.');
        }

        $updated = $this->programService->updateTrack(
            $track,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatTrack($updated),
        ]);
    }

    public function updateTrackStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $track = $this->trackRepository->findById($id);
        if (! $track) {
            abort(404, 'Academic track not found.');
        }

        $updated = $this->programService->updateTrackStatus(
            $track,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatTrack($updated),
        ]);
    }

    protected function formatProgram(AcademicProgram $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'department_id' => $p->department_id,
            'education_level_id' => $p->education_level_id,
            'name_bn' => $p->name_bn,
            'name_en' => $p->name_en,
            'description_bn' => $p->description_bn,
            'description_en' => $p->description_en,
            'scheme' => $p->scheme->value,
            'duration_years' => $p->duration_years,
            'state' => $p->state->value,
            'department' => $p->relationLoaded('department') && $p->department ? [
                'id' => $p->department->id,
                'code' => $p->department->code,
                'name_bn' => $p->department->name_bn,
                'name_en' => $p->department->name_en,
            ] : null,
            'education_level' => $p->relationLoaded('educationLevel') && $p->educationLevel ? [
                'id' => $p->educationLevel->id,
                'code' => $p->educationLevel->code,
                'name_bn' => $p->educationLevel->name_bn,
                'name_en' => $p->educationLevel->name_en,
                'authority' => $p->educationLevel->relationLoaded('authority') && $p->educationLevel->authority ? [
                    'id' => $p->educationLevel->authority->id,
                    'code' => $p->educationLevel->authority->code,
                    'name_bn' => $p->educationLevel->authority->name_bn,
                    'name_en' => $p->educationLevel->authority->name_en,
                ] : null,
            ] : null,
        ];
    }

    protected function formatClass(ProgramClass $c): array
    {
        return [
            'id' => $c->id,
            'program_id' => $c->program_id,
            'code' => $c->code,
            'name_bn' => $c->name_bn,
            'name_en' => $c->name_en,
            'stage_number' => $c->stage_number,
            'sort_order' => $c->sort_order,
            'is_active' => $c->is_active,
        ];
    }

    protected function formatTrack(AcademicTrack $t): array
    {
        return [
            'id' => $t->id,
            'program_id' => $t->program_id,
            'code' => $t->code,
            'name_bn' => $t->name_bn,
            'name_en' => $t->name_en,
            'is_active' => $t->is_active,
        ];
    }
}

