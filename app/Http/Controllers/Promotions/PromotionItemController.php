<?php

namespace App\Http\Controllers\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotions\UpdatePromotionItemRequest;
use App\Models\PromotionAttempt;
use App\Services\Promotions\PromotionBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionItemController extends Controller
{
    public function __construct(
        protected PromotionBatchService $batchService
    ) {}

    /**
     * List items in a batch.
     */
    public function index(Request $request, int $batchId): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 25);
        $items = $this->batchService->listItems($batchId, $request->all(), $perPage);

        return response()->json($items);
    }

    /**
     * Update an item in a draft batch.
     */
    public function update(UpdatePromotionItemRequest $request, int $batchId, int $itemId): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $item = $this->batchService->updateItem($batchId, $itemId, $request->validated(), $actorId);

        return response()->json([
            'message' => 'Batch item updated successfully.',
            'data' => $item,
        ]);
    }

    /**
     * Remove an item from a draft batch.
     */
    public function destroy(Request $request, int $batchId, int $itemId): JsonResponse
    {
        $this->batchService->removeItem($batchId, $itemId);

        return response()->json([
            'message' => 'Batch item removed successfully.',
        ]);
    }

    /**
     * Get attempt history for an item.
     */
    public function attempts(int $batchId, int $itemId): JsonResponse
    {
        $attempts = PromotionAttempt::where('promotion_item_id', $itemId)
            ->orderBy('attempt_number', 'asc')
            ->get();

        return response()->json([
            'data' => $attempts,
        ]);
    }
}

