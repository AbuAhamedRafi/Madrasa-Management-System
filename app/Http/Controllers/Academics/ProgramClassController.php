<?php

namespace App\Http\Controllers\Academics;

use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreProgramClassRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateProgramClassRequest;
use App\Http\Resources\Academics\ProgramClassResource;
use App\Repositories\Academics\ProgramClassRepository;
use App\Services\Academics\ProgramClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramClassController extends Controller
{
    public function __construct(
        protected ProgramClassRepository $classRepository,
        protected ProgramClassService $classService
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
            'data' => ProgramClassResource::collection($paginator->items()),
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
        $class = $this->classRepository->findById($id);
        if (! $class) {
            abort(404, 'Program class not found.');
        }

        return response()->json([
            'data' => new ProgramClassResource($class),
        ]);
    }

    public function store(StoreProgramClassRequest $request): JsonResponse
    {
        $class = $this->classService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new ProgramClassResource($class),
        ], 201);
    }

    public function update(UpdateProgramClassRequest $request, int $id): JsonResponse
    {
        $class = $this->classRepository->findById($id);
        if (! $class) {
            abort(404, 'Program class not found.');
        }

        $updated = $this->classService->update(
            $class,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new ProgramClassResource($updated),
        ]);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $class = $this->classRepository->findById($id);
        if (! $class) {
            abort(404, 'Program class not found.');
        }

        $updated = $this->classService->updateStatus(
            $class,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new ProgramClassResource($updated),
        ]);
    }
}

