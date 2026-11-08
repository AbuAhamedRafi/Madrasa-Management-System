<?php

namespace App\Http\Controllers\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\DataExchange\ImportMappingValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportMappingController extends Controller
{
    public function __construct(
        protected ImportMappingValidationService $mappingService
    ) {}

    /**
     * Resolve and authorize ImportJob.
     */
    protected function resolveAuthorizedJob(Request $request, string $id, string $permission): ImportJob
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission($permission)) {
            throw DataExchangeDomainException::forbidden("You do not have permission '{$permission}'.");
        }

        if ($user->hasRole('ACCOUNTS') && !$user->hasRole('ADMIN')) {
            throw DataExchangeDomainException::forbidden('Accounts staff cannot access student import jobs.');
        }

        $importJob = is_numeric($id)
            ? ImportJob::findOrFail($id)
            : ImportJob::where('job_number', $id)->firstOrFail();

        if ($user->hasRole('OFFICE_STAFF') && !$user->hasRole('ADMIN') && $importJob->requester_id !== $user->id) {
            throw DataExchangeDomainException::forbidden('You can only access import jobs you initiated.');
        }

        return $importJob;
    }

    /**
     * Detect sheet columns and return target schema and auto-mappings (F04).
     */
    public function columns(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.read');

        $sheet = $request->query('sheet');
        $headerRow = (int)$request->query('header_row', 1);

        $result = $this->mappingService->detectColumns($job, $sheet, $headerRow);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Save column mapping and invalidate preview (F04).
     */
    public function saveMapping(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.mapping.write');

        $validated = $request->validate([
            'sheet' => ['nullable', 'string'],
            'header_row' => ['nullable', 'integer', 'min:1'],
            'mapping' => ['required', 'array'],
            'options' => ['nullable', 'array'],
        ]);

        $columnMapping = $this->mappingService->saveMapping($job, $validated, $request->user()->id);

        return response()->json([
            'data' => [
                'id' => $columnMapping->id,
                'import_job_id' => $columnMapping->import_job_id,
                'version' => $columnMapping->version,
                'sheet' => $columnMapping->sheet,
                'header_row' => $columnMapping->header_row,
                'mapping' => $columnMapping->mapping_json,
                'options' => $columnMapping->options_json,
                'created_at' => $columnMapping->created_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Trigger read-only preview validation (F04).
     */
    public function validateJob(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.validate');

        $result = $this->mappingService->validateImport($job, $request->user()->id);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Get preview summary and token status (F04).
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.read');

        $result = $this->mappingService->getPreview($job);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Get preview rows with paginated filtering (F04).
     */
    public function rows(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.read');

        $paginator = $this->mappingService->getRows($job, $request);

        return response()->json($paginator);
    }
}
