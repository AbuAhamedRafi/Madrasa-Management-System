<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingIdempotencyService;
use App\Services\Billing\BillingRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingRunController extends Controller
{
    public function __construct(
        private readonly BillingRunService $runService,
        private readonly BillingIdempotencyService $idempotencyService
    ) {}

    /**
     * GET /api/v1/billing-runs
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->runService->listRuns($request->all());

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
     * POST /api/v1/billing-runs/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $preview = $this->runService->previewRun($request->all());

        return response()->json([
            'data' => $preview,
        ]);
    }

    /**
     * POST /api/v1/billing-runs
     */
    public function store(Request $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        $run = $this->idempotencyService->execute(
            $idempotencyKey,
            'BILLING_RUN_CREATE',
            $request->all(),
            fn () => $this->runService->createRun($request->all(), $actorId, $idempotencyKey)
        );

        return response()->json([
            'message' => 'Billing run created successfully.',
            'data' => $run,
        ], 201);
    }

    /**
     * GET /api/v1/billing-runs/{id}
     */
    public function show(int $id): JsonResponse
    {
        $run = $this->runService->getRunDetail($id);

        return response()->json([
            'data' => $run,
        ]);
    }

    /**
     * GET /api/v1/billing-runs/{id}/items
     */
    public function items(Request $request, int $id): JsonResponse
    {
        $paginator = $this->runService->listRunItems($id, $request->all());

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
     * POST /api/v1/billing-runs/{id}/execute
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        $result = $this->idempotencyService->execute(
            $idempotencyKey,
            "BILLING_RUN_EXECUTE:{$id}",
            $request->all(),
            fn () => $this->runService->executeRun($id, $actorId)
        );

        return response()->json([
            'message' => 'Billing run executed.',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/billing-runs/{id}/retry
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $result = $this->runService->retryFailedRun($id, $actorId);

        return response()->json([
            'message' => 'Billing run retry completed.',
            'data' => $result,
        ]);
    }
}
