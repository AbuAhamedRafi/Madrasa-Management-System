<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingIdempotencyService;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly BillingIdempotencyService $idempotencyService
    ) {}

    /**
     * GET /api/v1/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->invoiceService->listInvoices($request->all());

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
     * GET /api/v1/invoices/{id}
     */
    public function show(int $id): JsonResponse
    {
        $invoice = $this->invoiceService->getInvoiceDetail($id);

        return response()->json([
            'data' => $invoice,
        ]);
    }

    /**
     * POST /api/v1/invoices/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $preview = $this->invoiceService->previewManualInvoice($request->all());

        return response()->json([
            'data' => $preview,
        ]);
    }

    /**
     * POST /api/v1/invoices
     */
    public function store(Request $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        $invoice = $this->idempotencyService->execute(
            $idempotencyKey,
            'INVOICE_CREATE',
            $request->all(),
            fn () => $this->invoiceService->createDraftInvoice($request->all(), $actorId)
        );

        return response()->json([
            'message' => 'Invoice draft created successfully.',
            'data' => $invoice,
        ], 201);
    }

    /**
     * POST /api/v1/invoices/{id}/issue
     */
    public function issue(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        $invoice = $this->idempotencyService->execute(
            $idempotencyKey,
            "INVOICE_ISSUE:{$id}",
            $request->all(),
            fn () => $this->invoiceService->issueInvoice($id, $actorId)
        );

        return response()->json([
            'message' => 'Invoice issued successfully.',
            'data' => $invoice,
        ]);
    }

    /**
     * POST /api/v1/invoices/{id}/void
     */
    public function void(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $reason = (string) ($request->input('reason') ?? $request->input('void_reason') ?? '');

        $invoice = $this->invoiceService->voidInvoice($id, $reason, $actorId);

        return response()->json([
            'message' => 'Invoice voided successfully.',
            'data' => $invoice,
        ]);
    }
}

