<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAuthorityRequest;
use App\Http\Requests\Academics\UpdateAuthorityRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Resources\Academics\AuthorityResource;
use App\Repositories\Academics\AuthorityRepository;
use App\Services\Academics\AuthorityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorityController extends Controller
{
    public function __construct(
        protected AuthorityRepository $authorityRepository,
        protected AuthorityService $authorityService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->authorityRepository->paginate(
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20)
        );

        return response()->json([
            'data' => AuthorityResource::collection($paginator->items()),
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
        $authority = $this->authorityRepository->findById($id);
        if (! $authority) {
            abort(404, 'Education authority not found.');
        }

        return response()->json([
            'data' => new AuthorityResource($authority),
        ]);
    }

    public function store(StoreAuthorityRequest $request): JsonResponse
    {
        $authority = $this->authorityService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AuthorityResource($authority),
        ], 201);
    }

    public function update(UpdateAuthorityRequest $request, int $id): JsonResponse
    {
        $authority = $this->authorityRepository->findById($id);
        if (! $authority) {
            abort(404, 'Education authority not found.');
        }

        $updated = $this->authorityService->update(
            $authority,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AuthorityResource($updated),
        ]);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $authority = $this->authorityRepository->findById($id);
        if (! $authority) {
            abort(404, 'Education authority not found.');
        }

        $updated = $this->authorityService->updateStatus(
            $authority,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AuthorityResource($updated),
        ]);
    }
}

