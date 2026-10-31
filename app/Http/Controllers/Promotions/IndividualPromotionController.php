<?php

namespace App\Http\Controllers\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotions\ExecuteIndividualPromotionRequest;
use App\Http\Requests\Promotions\PreviewIndividualPromotionRequest;
use App\Services\Promotions\IndividualPromotionService;
use Illuminate\Http\JsonResponse;

class IndividualPromotionController extends Controller
{
    public function __construct(
        protected IndividualPromotionService $individualPromotionService
    ) {}

    /**
     * Preview individual student promotion/repeat eligibility.
     */
    public function preview(PreviewIndividualPromotionRequest $request): JsonResponse
    {
        $result = $this->individualPromotionService->preview($request->validated());

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Execute individual student promotion/repeat.
     */
    public function store(ExecuteIndividualPromotionRequest $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $result = $this->individualPromotionService->promote(
            $request->validated(),
            $actorId,
            $idempotencyKey
        );

        return response()->json([
            'data' => $result,
        ], 201);
    }
}

