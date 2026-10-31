<?php

namespace App\Http\Controllers\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotions\CreatePromotionBatchRequest;
use App\Http\Requests\Promotions\UpdatePromotionBatchRequest;
use App\Models\PromotionEvent;
use App\Services\Promotions\PromotionBatchService;
use App\Services\Promotions\PromotionExecutionService;
use App\Services\Promotions\PromotionExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromotionBatchController extends Controller
{
    public function __construct(
        protected PromotionBatchService $batchService,
        protected PromotionExecutionService $executionService,
        protected PromotionExportService $exportService
    ) {}

    /**
     * List batches with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $batches = $this->batchService->listBatches($request->all(), $perPage);

        return response()->json($batches);
    }

    /**
     * Create a new draft promotion batch.
     */
    public function store(CreatePromotionBatchRequest $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $batch = $this->batchService->createBatch($request->validated(), $actorId);

        return response()->json([
            'message' => 'Promotion batch created successfully.',
            'data' => $batch,
        ], 201);
    }

    /**
     * Get single batch details.
     */
    public function show(int $id): JsonResponse
    {
        $batch = $this->batchService->getBatch($id);

        return response()->json([
            'data' => $batch,
        ]);
    }

    /**
     * Update draft batch.
     */
    public function update(UpdatePromotionBatchRequest $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $batch = $this->batchService->updateBatch($id, $request->validated(), $actorId);

        return response()->json([
            'message' => 'Promotion batch updated successfully.',
            'data' => $batch,
        ]);
    }

    /**
     * Cancel draft batch.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $batch = $this->batchService->cancelBatch($id, $actorId);

        return response()->json([
            'message' => 'Promotion batch cancelled successfully.',
            'data' => $batch,
        ]);
    }

    /**
     * Run read-only preview evaluation on all items in batch.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $result = $this->batchService->previewBatch($id, $actorId);

        return response()->json([
            'message' => 'Batch preview generated successfully.',
            'data' => $result,
        ]);
    }

    /**
     * Execute bulk batch.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $result = $this->executionService->executeBatch($id, $actorId, $idempotencyKey);

        return response()->json([
            'message' => 'Promotion batch execution started.',
            'data' => $result,
        ]);
    }

    /**
     * Pause running batch.
     */
    public function pause(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $batch = $this->executionService->pauseBatch($id, $actorId);

        return response()->json([
            'message' => 'Batch paused successfully.',
            'data' => $batch,
        ]);
    }

    /**
     * Resume paused batch.
     */
    public function resume(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $result = $this->executionService->resumeBatch($id, $actorId);

        return response()->json([
            'message' => 'Batch execution resumed.',
            'data' => $result,
        ]);
    }

    /**
     * Retry failed items in completed batch.
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $result = $this->executionService->retryFailedItems($id, $actorId);

        return response()->json([
            'message' => 'Batch retry executed.',
            'data' => $result,
        ]);
    }

    /**
     * Export batch results to CSV.
     */
    public function export(int $id): StreamedResponse
    {
        return $this->exportService->exportBatchItemsCsv($id);
    }

    /**
     * Get batch events / audit history.
     */
    public function events(int $id): JsonResponse
    {
        $events = PromotionEvent::with('actor')
            ->where('promotion_batch_id', $id)
            ->orderBy('occurred_at', 'desc')
            ->get();

        return response()->json([
            'data' => $events,
        ]);
    }
}

