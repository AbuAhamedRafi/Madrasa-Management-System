<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAuthorityRequest;
use App\Http\Requests\Academics\StoreEducationLevelRequest;
use App\Http\Requests\Academics\UpdateAuthorityRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateEducationLevelRequest;
use App\Models\EducationAuthority;
use App\Models\EducationLevel;
use App\Repositories\Academics\AuthorityRepository;
use App\Repositories\Academics\EducationLevelRepository;
use App\Services\Academics\AuthorityLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorityLevelController extends Controller
{
    public function __construct(
        protected AuthorityRepository $authorityRepository,
        protected EducationLevelRepository $levelRepository,
        protected AuthorityLevelService $authorityLevelService
    ) {}

    public function indexAuthorities(Request $request): JsonResponse
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
            'data' => collect($paginator->items())->map(fn (EducationAuthority $a) => $this->formatAuthority($a))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showAuthority(int $id): JsonResponse
    {
        $authority = $this->authorityRepository->findById($id);
        if (! $authority) {
            abort(404, 'Education authority not found.');
        }

        return response()->json([
            'data' => $this->formatAuthority($authority, true),
        ]);
    }

    public function storeAuthority(StoreAuthorityRequest $request): JsonResponse
    {
        $authority = $this->authorityLevelService->createAuthority(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatAuthority($authority),
        ], 201);
    }

    public function updateAuthority(UpdateAuthorityRequest $request, int $id): JsonResponse
    {
        $authority = $this->authorityRepository->findById($id);
        if (! $authority) {
            abort(404, 'Education authority not found.');
        }

        $updated = $this->authorityLevelService->updateAuthority(
            $authority,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatAuthority($updated),
        ]);
    }

    public function updateAuthorityStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $authority = $this->authorityRepository->findById($id);
        if (! $authority) {
            abort(404, 'Education authority not found.');
        }

        $updated = $this->authorityLevelService->updateAuthorityStatus(
            $authority,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatAuthority($updated),
        ]);
    }

    public function indexLevels(Request $request): JsonResponse
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
            'data' => collect($paginator->items())->map(fn (EducationLevel $l) => $this->formatLevel($l))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showLevel(int $id): JsonResponse
    {
        $level = $this->levelRepository->findById($id);
        if (! $level) {
            abort(404, 'Education level not found.');
        }

        return response()->json([
            'data' => $this->formatLevel($level),
        ]);
    }

    public function storeLevel(StoreEducationLevelRequest $request): JsonResponse
    {
        $level = $this->authorityLevelService->createLevel(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatLevel($level),
        ], 201);
    }

    public function updateLevel(UpdateEducationLevelRequest $request, int $id): JsonResponse
    {
        $level = $this->levelRepository->findById($id);
        if (! $level) {
            abort(404, 'Education level not found.');
        }

        $updated = $this->authorityLevelService->updateLevel(
            $level,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatLevel($updated),
        ]);
    }

    public function updateLevelStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $level = $this->levelRepository->findById($id);
        if (! $level) {
            abort(404, 'Education level not found.');
        }

        $updated = $this->authorityLevelService->updateLevelStatus(
            $level,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatLevel($updated),
        ]);
    }

    protected function formatAuthority(EducationAuthority $a, bool $includeLevels = false): array
    {
        $data = [
            'id' => $a->id,
            'code' => $a->code,
            'name_bn' => $a->name_bn,
            'name_en' => $a->name_en,
            'is_active' => $a->is_active,
            'sort_order' => $a->sort_order,
            'levels_count' => $a->levels_count ?? $a->levels()->count(),
        ];

        if ($includeLevels && $a->relationLoaded('levels')) {
            $data['levels'] = $a->levels->map(fn ($l) => $this->formatLevel($l))->values();
        }

        return $data;
    }

    protected function formatLevel(EducationLevel $l): array
    {
        return [
            'id' => $l->id,
            'authority_id' => $l->authority_id,
            'code' => $l->code,
            'name_bn' => $l->name_bn,
            'name_en' => $l->name_en,
            'is_active' => $l->is_active,
            'sort_order' => $l->sort_order,
            'authority' => $l->relationLoaded('authority') && $l->authority ? [
                'id' => $l->authority->id,
                'code' => $l->authority->code,
                'name_bn' => $l->authority->name_bn,
                'name_en' => $l->authority->name_en,
            ] : null,
        ];
    }
}

