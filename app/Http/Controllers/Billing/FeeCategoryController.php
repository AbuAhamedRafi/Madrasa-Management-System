<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\BillingDomainException;
use App\Http\Controllers\Controller;
use App\Services\Billing\FeeCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeCategoryController extends Controller
{
    public function __construct(
        private readonly FeeCategoryService $categoryService
    ) {}

    /**
     * GET /api/v1/fee-categories
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->categoryService->listCategories($request->all());

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/fee-categories
     */
    public function store(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code', ''));
        $nameBn = trim((string) $request->input('name_bn', ''));
        $nameEn = trim((string) $request->input('name_en', ''));

        if ($code === '' || $nameBn === '' || $nameEn === '') {
            throw BillingDomainException::validation(
                'MISSING_REQUIRED_FIELDS',
                'code, name_bn, and name_en are required to create a fee category.'
            );
        }

        $actorId = (int) $request->user()->id;
        $category = $this->categoryService->createCategory($request->all(), $actorId);

        return response()->json([
            'message' => 'Fee category created successfully.',
            'data' => $category,
        ], 201);
    }

    /**
     * PATCH /api/v1/fee-categories/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $category = $this->categoryService->updateCategory($id, $request->all(), $actorId);

        return response()->json([
            'message' => 'Fee category updated successfully.',
            'data' => $category,
        ]);
    }
}

