<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreEducationLevelRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateEducationLevelRequest;
use App\Http\Resources\Academics\EducationLevelResource;
use App\Repositories\Academics\EducationLevelRepository;
use App\Services\Academics\EducationLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EducationLevelController extends Controller
{
    public function __construct(
        protected EducationLevelRepository $levelRepository,
        protected EducationLevelService $levelService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $authorityId = $request->filled('authority_id') ? (int) $request->query('authority_id') : null;
        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->levelRepository->paginate(
            $authorityId,
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50)
        );

        return response()->json([
            'data' => EducationLevelResource::collection($paginator->items()),
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
        $level = $this->levelRepository->findById($id);
        if (! $level) {
            abort(404, 'Education level not found.');
        }

        return response()->json([
            'data' => new EducationLevelResource($level),
        ]);
    }

    public function store(StoreEducationLevelRequest $request): JsonResponse
    {
        $level = $this->levelService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new EducationLevelResource($level),
        ], 201);
    }

    public function update(UpdateEducationLevelRequest $request, int $id): JsonResponse
    {
        $level = $this->levelRepository->findById($id);
        if (! $level) {
            abort(404, 'Education level not found.');
        }

        $updated = $this->levelService->update(
            $level,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new EducationLevelResource($updated),
        ]);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $level = $this->levelRepository->findById($id);
        if (! $level) {
            abort(404, 'Education level not found.');
        }

        $updated = $this->levelService->updateStatus(
            $level,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new EducationLevelResource($updated),
        ]);
    }
}

