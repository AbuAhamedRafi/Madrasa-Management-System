<?php

namespace App\Http\Controllers\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Services\DataExchange\ExportExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportJobController extends Controller
{
    public function __construct(
        protected ExportExecutionService $executionService
    ) {}

    /**
     * Dry run count and estimated size for an export request (F10).
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.create')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to preview exports.');
        }

        $validated = $request->validate([
            'dataset' => ['required', 'string'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string'],
            'format' => ['nullable', 'string', 'in:XLSX,PDF,xlsx,pdf'],
            'filters' => ['nullable', 'array'],
        ]);

        $preview = $this->executionService->preview($validated, $user);

        return response()->json([
            'data' => $preview,
        ]);
    }

    /**
     * Create and execute export job (F10).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.create')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to create export jobs.');
        }

        $validated = $request->validate([
            'dataset' => ['required', 'string'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string'],
            'format' => ['nullable', 'string', 'in:XLSX,PDF,xlsx,pdf'],
            'locale' => ['nullable', 'string', 'in:bn,en'],
            'filters' => ['nullable', 'array'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        $job = $this->executionService->createJob($validated, $user, $idempotencyKey);

        return response()->json([
            'data' => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'dataset' => $job->dataset_key->value ?? $job->dataset_key,
                'format' => $job->format->value ?? $job->format,
                'status' => $job->status->value ?? $job->status,
                'row_count' => $job->row_count,
                'cutoff_at' => $job->cutoff_at?->toIso8601String(),
                'expires_at' => $job->expires_at?->toIso8601String(),
                'created_at' => $job->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Get export job details (F10).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.read')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to view export jobs.');
        }

        $job = is_numeric($id)
            ? ExportJob::with(['requester', 'artifact', 'events'])->find($id)
            : ExportJob::with(['requester', 'artifact', 'events'])->where('job_number', $id)->first();

        if (!$job) {
            throw DataExchangeDomainException::notFound('EXPORT_JOB_NOT_FOUND', "Export job '{$id}' not found.");
        }

        // RBAC isolation check
        $roleNames = $user->roles->pluck('name')->map(fn($n) => strtoupper($n))->toArray();
        if (!in_array('ADMIN', $roleNames) && $job->requester_id !== $user->id) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have access to this export job.');
        }

        return response()->json([
            'data' => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'requester' => [
                    'id' => $job->requester->id,
                    'name' => $job->requester->name,
                ],
                'dataset' => $job->dataset_key->value ?? $job->dataset_key,
                'format' => $job->format->value ?? $job->format,
                'status' => $job->status->value ?? $job->status,
                'row_count' => $job->row_count,
                'columns' => $job->column_json,
                'filters' => $job->filter_json,
                'scope_snapshot' => $job->scope_snapshot_json,
                'expires_at' => $job->expires_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'cancelled_at' => $job->cancelled_at?->toIso8601String(),
                'artifact' => $job->artifact ? [
                    'filename' => $job->artifact->filename,
                    'byte_size' => $job->artifact->byte_size,
                    'mime' => $job->artifact->mime,
                    'sha256' => $job->artifact->sha256,
                ] : null,
            ],
        ]);
    }

    /**
     * Cancel queued/running export job (F10).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.cancel')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to cancel export jobs.');
        }

        $job = is_numeric($id)
            ? ExportJob::find($id)
            : ExportJob::where('job_number', $id)->first();

        if (!$job) {
            throw DataExchangeDomainException::notFound('EXPORT_JOB_NOT_FOUND', "Export job '{$id}' not found.");
        }

        $updated = $this->executionService->cancelJob($job, $user);

        return response()->json([
            'data' => [
                'id' => $updated->id,
                'job_number' => $updated->job_number,
                'status' => $updated->status->value ?? $updated->status,
                'cancelled_at' => $updated->cancelled_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Download artifact file with live access recheck and 410 expiry (F10, F12).
     */
    public function download(Request $request, string $id): Response
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.download')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to download export files.');
        }

        $job = is_numeric($id)
            ? ExportJob::find($id)
            : ExportJob::where('job_number', $id)->first();

        if (!$job) {
            throw DataExchangeDomainException::notFound('EXPORT_JOB_NOT_FOUND', "Export job '{$id}' not found.");
        }

        return $this->executionService->downloadArtifact($job, $user);
    }
}
