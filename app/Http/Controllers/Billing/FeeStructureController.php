<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingIdempotencyService;
use App\Services\Billing\FeeStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeStructureController extends Controller
{
    public function __construct(
        private readonly FeeStructureService $structureService,
        private readonly BillingIdempotencyService $idempotencyService
    ) {}

    /**
     * GET /api/v1/fee-structures
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->structureService->listStructures($request->all());

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
     * POST /api/v1/fee-structures
     */
    public function store(Request $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $structure = $this->structureService->createDraft($request->all(), $actorId);

        return response()->json([
            'message' => 'Fee structure draft created successfully.',
            'data' => $structure,
        ], 201);
    }

    /**
     * GET /api/v1/fee-structures/{id}
     */
    public function show(int $id): JsonResponse
    {
        $structure = $this->structureService->getStructure($id);

        return response()->json([
            'data' => $structure,
        ]);
    }

    /**
     * PATCH /api/v1/fee-structures/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $structure = $this->structureService->updateDraft($id, $request->all(), $actorId);

        return response()->json([
            'message' => 'Fee structure draft updated successfully.',
            'data' => $structure,
        ]);
    }

    /**
     * POST /api/v1/fee-structures/{id}/publish
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        $result = $this->idempotencyService->execute(
            $idempotencyKey,
            "FEE_STRUCTURE_PUBLISH:{$id}",
            $request->all(),
            fn () => $this->structureService->publishStructure($id, $actorId)
        );

        return response()->json([
            'message' => 'Fee structure published successfully.',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/fee-structures/{id}/archive
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $structure = $this->structureService->archiveStructure($id, $actorId);

        return response()->json([
            'message' => 'Fee structure archived successfully.',
            'data' => $structure,
        ]);
    }
}

