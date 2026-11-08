<?php

namespace App\Http\Controllers\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\DataExchange\ImportUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportJobController extends Controller
{
    public function __construct(
        protected ImportUploadService $uploadService
    ) {}

    /**
     * Upload an import file and initialize an import job (F03).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('imports.create')) {
            throw DataExchangeDomainException::forbidden('You do not have permission to create import jobs.');
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // max 20MB
            'mode' => ['required', 'string'],
            'template_version' => ['required', 'string'],
            'locale' => ['nullable', 'string', 'in:bn,en'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        $result = $this->uploadService->handleUpload(
            file: $request->file('file'),
            modeStr: $validated['mode'],
            templateVersion: $validated['template_version'],
            locale: $validated['locale'] ?? 'bn',
            actorId: $user->id,
            idempotencyKey: $idempotencyKey
        );

        return response()->json([
            'data' => $result['data'],
        ], $result['status_code']);
    }

    /**
     * Show import job details with RBAC scoping (F01, F03).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('imports.read')) {
            throw DataExchangeDomainException::forbidden('You do not have permission to view import jobs.');
        }

        // ACCOUNTS role cannot access student imports
        if ($user->hasRole('ACCOUNTS') && !$user->hasRole('ADMIN')) {
            throw DataExchangeDomainException::forbidden('Accounts staff cannot access student imports.');
        }

        $importJob = is_numeric($id)
            ? ImportJob::with('requester:id,name,email')->findOrFail($id)
            : ImportJob::with('requester:id,name,email')->where('job_number', $id)->firstOrFail();

        // OFFICE_STAFF can only access their own imports unless ADMIN
        if ($user->hasRole('OFFICE_STAFF') && !$user->hasRole('ADMIN') && $importJob->requester_id !== $user->id) {
            throw DataExchangeDomainException::forbidden('You can only view import jobs that you initiated.');
        }

        return response()->json([
            'data' => [
                'id' => $importJob->id,
                'job_number' => $importJob->job_number,
                'requester' => [
                    'id' => $importJob->requester?->id,
                    'name' => $importJob->requester?->name,
                    'email' => $importJob->requester?->email,
                ],
                'mode' => $importJob->mode->value,
                'template_code' => $importJob->template_code,
                'template_version' => $importJob->template_version,
                'locale' => $importJob->locale,
                'source_name' => $importJob->source_name,
                'sha256' => $importJob->sha256,
                'byte_size' => $importJob->byte_size,
                'detected_sheets' => $importJob->detected_sheets_json,
                'state' => $importJob->state->value,
                'lock_version' => $importJob->lock_version,
                'mapping_version' => $importJob->mapping_version,
                'reviewed_version' => $importJob->reviewed_version,
                'preview_token' => $importJob->preview_token_hash,
                'preview_expires_at' => $importJob->preview_expires_at?->toIso8601String(),
                'total_rows' => $importJob->total_rows,
                'ready_rows' => $importJob->ready_rows,
                'review_rows' => $importJob->review_rows,
                'invalid_rows' => $importJob->invalid_rows,
                'committed_rows' => $importJob->committed_rows,
                'skipped_rows' => $importJob->skipped_rows,
                'failed_rows' => $importJob->failed_rows,
                'result_storage_key' => $importJob->result_storage_key ? true : false,
                'created_at' => $importJob->created_at->toIso8601String(),
                'updated_at' => $importJob->updated_at->toIso8601String(),
            ]
        ]);
    }
}
