<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreDepartmentRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateDepartmentRequest;
use App\Models\Department;
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
            'data' => collect($paginator->items())->map(fn (Department $d) => $this->format($d))->values(),
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
            'data' => $this->format($department, true),
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
            'data' => $this->format($department),
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
            'data' => $this->format($updated),
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
            'data' => $this->format($updated),
        ]);
    }

    protected function format(Department $d, bool $includePrograms = false): array
    {
        $data = [
            'id' => $d->id,
            'code' => $d->code,
            'name_bn' => $d->name_bn,
            'name_en' => $d->name_en,
            'description_bn' => $d->description_bn,
            'description_en' => $d->description_en,
            'sort_order' => $d->sort_order,
            'is_active' => $d->is_active,
            'programs_count' => $d->programs_count ?? $d->programs()->count(),
        ];

        if ($includePrograms && $d->relationLoaded('programs')) {
            $data['programs'] = $d->programs->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name_bn' => $p->name_bn,
                'name_en' => $p->name_en,
                'scheme' => $p->scheme->value,
                'state' => $p->state->value,
            ])->values();
        }

        return $data;
    }
}

