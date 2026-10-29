<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreDepartmentRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateDepartmentRequest;
use App\Http\Resources\Academics\DepartmentResource;
use App\Repositories\Academics\DepartmentRepository;
use App\Services\Academics\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(
        protected DepartmentRepository $departmentRepository,
        protected DepartmentService $departmentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->departmentRepository->paginate(
            $request->query('search'),
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20)
        );

        return response()->json([
            'data' => DepartmentResource::collection($paginator->items()),
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
        $department = $this->departmentRepository->findById($id);
        if (! $department) {
            abort(404, 'Department not found.');
        }

        return response()->json([
            'data' => new DepartmentResource($department),
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new DepartmentResource($department),
        ], 201);
    }

    public function update(UpdateDepartmentRequest $request, int $id): JsonResponse
    {
        $department = $this->departmentRepository->findById($id);
        if (! $department) {
            abort(404, 'Department not found.');
        }

        $updated = $this->departmentService->update(
            $department,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new DepartmentResource($updated),
        ]);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $department = $this->departmentRepository->findById($id);
        if (! $department) {
            abort(404, 'Department not found.');
        }

        $updated = $this->departmentService->updateStatus(
            $department,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new DepartmentResource($updated),
        ]);
    }
}
