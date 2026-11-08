<?php

namespace App\Services\DataExchange;

use App\Enums\ImportJobStatus;
use App\Enums\ImportMode;
use App\Exceptions\DataExchangeDomainException;
use App\Models\ImportJob;
use App\Models\ImportJobEvent;
use App\Models\ImportTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportUploadService
{
    public function __construct(
        protected XlsxWorkbookService $xlsxService,
        protected DataJobIdempotencyService $idempotencyService
    ) {}

    /**
     * Upload, scan, quarantine, and initialize an import job (F03).
     *
     * @throws DataExchangeDomainException
     */
    public function handleUpload(
        UploadedFile $file,
        string $modeStr,
        string $templateVersion,
        string $locale,
        int $actorId,
        ?string $idempotencyKey = null
    ): array {
        // 1. Verify Mode enum
        $mode = ImportMode::tryFrom(strtoupper($modeStr));
        if (!$mode) {
            throw DataExchangeDomainException::fileUnsafe("Invalid import mode '{$modeStr}'. Must be CREATE_ONLY, MATCH_UPDATE, or ENROLL_ONLY.");
        }

        // 2. Resolve template code based on mode
        $templateCode = match ($mode) {
            ImportMode::CREATE_ONLY => 'students_create',
            ImportMode::MATCH_UPDATE => 'student_update',
            ImportMode::ENROLL_ONLY => 'enrollment_stage',
        };

        // 3. Verify template version
        $template = ImportTemplate::where('code', $templateCode)
            ->where('version', $templateVersion)
            ->where('is_active', true)
            ->first();

        if (!$template) {
            throw DataExchangeDomainException::templateUnsupported(
                "Template '{$templateCode}' version '{$templateVersion}' is not supported."
            );
        }

        // 4. Compute file hash
        $fileRealPath = $file->getRealPath();
        if (!$fileRealPath || !file_exists($fileRealPath)) {
            throw DataExchangeDomainException::fileUnsafe('Uploaded file could not be read.');
        }

        $sha256 = hash_file('sha256', $fileRealPath);

        // 5. Check Idempotency with file SHA-256 in payload hash
        $payloadForHash = [
            'mode' => $mode->value,
            'template_version' => $templateVersion,
            'locale' => $locale,
            'sha256' => $sha256,
            'original_name' => $file->getClientOriginalName(),
        ];

        $replay = $this->idempotencyService->validateOrStart($actorId, 'IMPORT_UPLOAD', $idempotencyKey, $payloadForHash);
        if ($replay && !empty($replay['is_replay'])) {
            return [
                'status_code' => 200,
                'data' => $replay['data'],
            ];
        }

        try {
            // 6. Security scan: OpenXML package, macro rejection, zip bomb, external links (F03)
            $scanResult = $this->xlsxService->validateAndScanPackage($fileRealPath);

            // 7. Store in private quarantine directory
            $year = date('Y');
            $uuid = Str::uuid()->toString();
            $storagePath = "private/data-exchange/imports/{$year}/{$uuid}.xlsx";

            // Save to private disk (local or s3)
            Storage::disk('local')->put($storagePath, file_get_contents($fileRealPath));

            // 8. Generate sequence job_number (collision safe in PostgreSQL)
            $jobNumber = DB::transaction(function () use ($year) {
                $latest = ImportJob::where('job_number', 'like', "IMP-{$year}-%")
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $seq = 1;
                if ($latest && preg_match("/IMP-{$year}-(\d+)/", $latest->job_number, $m)) {
                    $seq = ((int) $m[1]) + 1;
                }

                return sprintf('IMP-%s-%04d', $year, $seq);
            });

            // 9. Create ImportJob record (requester is strictly actorId, ignoring any input - D08-F03-AT06)
            $importJob = ImportJob::create([
                'job_number' => $jobNumber,
                'requester_id' => $actorId,
                'mode' => $mode,
                'template_code' => $templateCode,
                'template_version' => $templateVersion,
                'locale' => $locale,
                'source_name' => $file->getClientOriginalName(),
                'storage_key' => $storagePath,
                'sha256' => $sha256,
                'byte_size' => $scanResult['byte_size'],
                'detected_sheets_json' => $scanResult['sheets'],
                'state' => ImportJobStatus::UPLOADED,
                'lock_version' => 1,
                'mapping_version' => 0,
                'reviewed_version' => 0,
                'retention_until' => now()->addDays(30),
            ]);

            // 10. Audit event
            ImportJobEvent::create([
                'import_job_id' => $importJob->id,
                'actor_id' => $actorId,
                'event' => 'UPLOADED',
                'before_state' => null,
                'after_state' => ImportJobStatus::UPLOADED->value,
                'reason_code' => 'INITIAL_UPLOAD',
                'context_redacted_json' => [
                    'source_name' => $importJob->source_name,
                    'sha256' => $sha256,
                    'mode' => $mode->value,
                ],
                'created_at' => now(),
            ]);

            $responseData = [
                'id' => $importJob->id,
                'job_number' => $importJob->job_number,
                'state' => $importJob->state->value,
                'sha256' => $importJob->sha256,
                'detected_sheets' => $scanResult['sheets'],
                'created_at' => $importJob->created_at->toIso8601String(),
            ];

            // Complete idempotency record
            $this->idempotencyService->complete(
                $actorId,
                'IMPORT_UPLOAD',
                $idempotencyKey,
                202,
                $responseData,
                'ImportJob',
                $importJob->id
            );

            return [
                'status_code' => 202,
                'data' => $responseData,
            ];

        } catch (\Throwable $e) {
            $this->idempotencyService->fail(
                $actorId,
                'IMPORT_UPLOAD',
                $idempotencyKey,
                $e instanceof DataExchangeDomainException ? $e->httpStatus : 422,
                ['error' => $e->getMessage()]
            );

            throw $e;
        }
    }
}
