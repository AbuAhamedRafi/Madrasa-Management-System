<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\FeeAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeAdjustmentController extends Controller
{
    public function __construct(
        private readonly FeeAdjustmentService $adjustmentService
    ) {}

    /**
     * GET /api/v1/adjustment-requests
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->adjustmentService->listRequests($request->all());

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
     * POST /api/v1/adjustment-requests
     */
    public function store(Request $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $adj = $this->adjustmentService->requestAdjustment($request->all(), $actorId);

        return response()->json([
            'message' => 'Fee adjustment request submitted for approval.',
            'data' => $adj,
        ], 201);
    }

    /**
     * GET /api/v1/adjustment-requests/{id}
     */
    public function show(int $id): JsonResponse
    {
        $adj = $this->adjustmentService->getRequestDetail($id);

        return response()->json([
            'data' => $adj,
        ]);
    }

    /**
     * POST /api/v1/adjustment-requests/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $adj = $this->adjustmentService->approveAdjustment($id, $request->all(), $actorId);

        return response()->json([
            'message' => 'Fee adjustment approved and applied to invoice.',
            'data' => $adj,
        ]);
    }

    /**
     * POST /api/v1/adjustment-requests/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $adj = $this->adjustmentService->rejectAdjustment($id, $request->all(), $actorId);

        return response()->json([
            'message' => 'Fee adjustment request rejected.',
            'data' => $adj,
        ]);
    }
}

