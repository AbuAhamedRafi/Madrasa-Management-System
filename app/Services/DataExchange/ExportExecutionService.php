<?php

namespace App\Services\DataExchange;

use App\Enums\ExportDatasetKey;
use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Exceptions\DataExchangeDomainException;
use App\Models\ExportArtifact;
use App\Models\ExportJob;
use App\Models\ExportJobEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportExecutionService
{
    public function __construct(
        protected ExportDatasetService $datasetService,
        protected XlsxWorkbookService $xlsxService,
        protected PdfReportWriterService $pdfService,
        protected DataJobIdempotencyService $idempotencyService
    ) {}

    /**
     * Preview dry-run statistics for export request.
     */
    public function preview(array $data, User $actor): array
    {
        $datasetKey = $data['dataset'] ?? '';
        $columns = $data['columns'] ?? [];
        $filters = $data['filters'] ?? [];
        $format = strtoupper($data['format'] ?? 'XLSX');

        $this->datasetService->validateExportRequest($datasetKey, $columns, $filters, $actor);

        $rowCount = $this->datasetService->previewCount($datasetKey, $filters, $actor);
        $colCount = count($columns);
        // Estimated KB: rough 0.15KB per cell for XLSX, 0.25KB for PDF
        $factor = ($format === 'PDF') ? 0.25 : 0.15;
        $estimatedKb = max(10, (int) round($rowCount * $colCount * $factor));

        return [
            'dataset' => $datasetKey,
            'format' => $format,
            'columns_count' => $colCount,
            'row_count' => $rowCount,
            'estimated_kb' => $estimatedKb,
            'cutoff_at' => Carbon::now()->toIso8601String(),
            'filters_applied' => $filters,
        ];
    }

    /**
     * Create and execute export job.
     */
    public function createJob(array $data, User $actor, ?string $idempotencyKey = null): ExportJob
    {
        $datasetKey = $data['dataset'] ?? '';
        $columns = $data['columns'] ?? [];
        $filters = $data['filters'] ?? [];
        $format = strtoupper($data['format'] ?? 'XLSX');
        $locale = $data['locale'] ?? 'bn';

        // 1. Idempotency Check
        if ($idempotencyKey) {
            $cached = $this->idempotencyService->check('EXPORT_CREATE', $actor->id, $idempotencyKey, $data);
            if ($cached) {
                return ExportJob::findOrFail($cached['result_id']);
            }
        }

        // 2. Validate Dataset and Columns
        $this->datasetService->validateExportRequest($datasetKey, $columns, $filters, $actor);

        $now = Carbon::now();
        $year = $now->format('Y');

        // 3. Generate Job Number
        $jobNumber = $this->generateJobNumber();

        // 4. Create ExportJob Record
        $job = ExportJob::create([
            'job_number' => $jobNumber,
            'requester_id' => $actor->id,
            'dataset_key' => $datasetKey,
            'filter_json' => $filters,
            'column_json' => $columns,
            'scope_snapshot_json' => [
                'actor_roles' => $actor->roles->pluck('name')->toArray(),
                'campus_id' => $actor->campus_id ?? null,
                'department_id' => $actor->department_id ?? null,
            ],
            'locale' => $locale,
            'format' => $format,
            'cutoff_at' => $now,
            'status' => ExportJobStatus::RUNNING,
            'expires_at' => $now->copy()->addDays(7), // 7-day TTL retention
        ]);

        $this->recordEvent($job, $actor, 'CREATED', ['initial_state' => 'RUNNING']);

        // 5. Execute Export Query & Content Generation
        try {
            $exportData = $this->datasetService->fetchExportData($datasetKey, $columns, $filters, $actor, $locale);
            $headers = $exportData['headers'];
            $rows = $exportData['rows'];
            $rowCount = $exportData['total_count'];

            $fileUuid = (string) Str::uuid();
            $ext = ($format === 'PDF') ? 'pdf' : 'xlsx';
            $fileName = "{$datasetKey}_{$now->format('Ymd_His')}.{$ext}";
            $relativePath = "data-exchange/exports/{$year}/{$fileUuid}.{$ext}";

            if ($format === 'PDF') {
                $content = $this->pdfService->generateReport(
                    title: "MMS Export: " . strtoupper($datasetKey),
                    metadata: [
                        'exported_by' => $actor->name,
                        'cutoff_at' => $now->toDateTimeString(),
                        'total_rows' => $rowCount,
                    ],
                    headers: $headers,
                    rows: $rows
                );
                $mime = 'application/pdf';
            } else {
                $content = $this->xlsxService->generateWorkbook([
                    'ExportData' => [
                        'headers' => $headers,
                        'rows' => $rows,
                    ],
                ]);
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }

            // 6. Store in Private Quarantine / Private Disk (NEVER public disk)
            Storage::disk('local')->put("private/{$relativePath}", $content);
            $sha256 = hash('sha256', $content);
            $byteSize = strlen($content);

            // 7. Create Artifact
            ExportArtifact::create([
                'export_job_id' => $job->id,
                'filename' => $fileName,
                'sha256' => $sha256,
                'object_key' => $relativePath,
                'byte_size' => $byteSize,
                'mime' => $mime,
                'expire_at' => $job->expires_at,
            ]);

            // 8. Update Job State
            $job->update([
                'status' => ExportJobStatus::READY,
                'row_count' => $rowCount,
                'storage_key' => $relativePath,
                'completed_at' => Carbon::now(),
            ]);

            $this->recordEvent($job, $actor, 'COMPLETED', [
                'row_count' => $rowCount,
                'byte_size' => $byteSize,
                'sha256' => $sha256,
            ]);

            if ($idempotencyKey) {
                $this->idempotencyService->complete(
                    'EXPORT_CREATE',
                    $actor->id,
                    $idempotencyKey,
                    'ExportJob',
                    $job->id,
                    201,
                    ['job_number' => $job->job_number, 'status' => 'READY']
                );
            }

            return $job->fresh(['requester', 'events', 'artifact']);
        } catch (\Throwable $e) {
            $job->update([
                'status' => ExportJobStatus::FAILED,
                'error_code' => 'EXPORT_FAILED',
                'error_message' => $e->getMessage(),
            ]);

            $this->recordEvent($job, $actor, 'FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Cooperative cancellation of export job.
     */
    public function cancelJob(ExportJob $job, User $actor): ExportJob
    {
        $roleNames = $actor->roles->pluck('name')->map(fn($n) => strtoupper($n))->toArray();
        $isAdmin = in_array('ADMIN', $roleNames);

        if (!$isAdmin && $job->requester_id !== $actor->id) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You can only cancel your own export jobs.');
        }

        if (in_array($job->status, [ExportJobStatus::READY, ExportJobStatus::CANCELLED, ExportJobStatus::EXPIRED])) {
            throw DataExchangeDomainException::jobStateConflict("Cannot cancel export job in status {$job->status->value}.");
        }

        $job->update([
            'status' => ExportJobStatus::CANCELLED,
            'cancelled_at' => Carbon::now(),
        ]);

        $this->recordEvent($job, $actor, 'CANCELLED');

        return $job->fresh();
    }

    /**
     * Verify authorization, TTL, and stream artifact download.
     */
    public function downloadArtifact(ExportJob $job, User $actor): BinaryFileResponse
    {
        // 1. Recheck live user status
        if (!$actor->is_active) {
            throw DataExchangeDomainException::forbidden('USER_INACTIVE', 'Your account is inactive.');
        }

        // 2. Recheck user permission and dataset scope (D08-F12-04)
        $roleNames = $actor->roles->pluck('name')->map(fn($n) => strtoupper($n))->toArray();
        $isAdmin = in_array('ADMIN', $roleNames);

        if (!$isAdmin && $job->requester_id !== $actor->id) {
            // Check dataset access
            $datasetKey = is_string($job->dataset_key) ? $job->dataset_key : $job->dataset_key->value;
            $this->datasetService->getColumns($datasetKey, $actor);
        }

        // 3. Recheck TTL Expiry (410 FILE_EXPIRED)
        if ($job->expires_at && $job->expires_at->isPast()) {
            $job->update(['status' => ExportJobStatus::EXPIRED]);
            throw DataExchangeDomainException::fileExpired('This export file has expired (7-day retention exceeded).');
        }

        $artifact = ExportArtifact::where('export_job_id', $job->id)->first();
        if (!$artifact || !Storage::disk('local')->exists("private/{$artifact->object_key}")) {
            throw DataExchangeDomainException::notFound('FILE_NOT_FOUND', 'Export file could not be located.');
        }

        $fullPath = Storage::disk('local')->path("private/{$artifact->object_key}");

        // Record download event
        $this->recordEvent($job, $actor, 'DOWNLOADED', ['artifact_id' => $artifact->id]);

        return response()->download(
            $fullPath,
            $artifact->filename,
            [
                'Content-Type' => $artifact->mime,
                'Cache-Control' => 'no-store, private',
            ]
        );
    }

    protected function generateJobNumber(): string
    {
        $year = date('Y');
        $prefix = "EXP-{$year}-";

        return DB::transaction(function () use ($prefix, $year) {
            $latest = ExportJob::where('job_number', 'like', "{$prefix}%")
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $nextSeq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest->job_number, $matches)) {
                $nextSeq = ((int) $matches[1]) + 1;
            }

            return sprintf("EXP-%s-%04d", $year, $nextSeq);
        });
    }

    protected function recordEvent(ExportJob $job, ?User $actor, string $event, array $context = []): void
    {
        ExportJobEvent::create([
            'export_job_id' => $job->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'context_redacted_json' => !empty($context) ? $context : null,
            'created_at' => Carbon::now(),
        ]);
    }
}
