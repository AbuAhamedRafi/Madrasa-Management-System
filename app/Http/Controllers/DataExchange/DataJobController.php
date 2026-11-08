<?php

namespace App\Http\Controllers\DataExchange;

use App\Enums\ExportDatasetKey;
use App\Enums\ExportJobStatus;
use App\Enums\ImportJobStatus;
use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Models\ExportArtifact;
use App\Models\ExportJob;
use App\Models\ExportJobEvent;
use App\Models\ImportJob;
use App\Models\ImportJobEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataJobController extends Controller
{
    /**
     * Authorized summary counts for own jobs / managed jobs (UI-01, UI-08, F01).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('ADMIN');
        $isStaff = $user->hasRole('OFFICE_STAFF');
        $isAccounts = $user->hasRole('ACCOUNTS');

        // Import queries
        $importQuery = ImportJob::query();
        if ($isAccounts && !$isAdmin) {
            // Accounts cannot see student imports
            $importQuery->whereRaw('1 = 0');
        } elseif ($isStaff && !$isAdmin) {
            $importQuery->where('requester_id', $user->id);
        }

        $totalImports = (clone $importQuery)->count();
        $needsReviewImports = (clone $importQuery)->where('state', ImportJobStatus::REVIEW_REQUIRED->value)->count();
        $runningImports = (clone $importQuery)->whereIn('state', [
            ImportJobStatus::VALIDATING->value,
            ImportJobStatus::QUEUED->value,
            ImportJobStatus::RUNNING->value,
        ])->count();

        // Export queries
        $exportQuery = ExportJob::query();
        if ($isAccounts && !$isAdmin) {
            $exportQuery->whereIn('dataset_key', [
                ExportDatasetKey::INVOICE_LEDGER->value,
                ExportDatasetKey::CASH_COLLECTIONS->value,
                ExportDatasetKey::CASHIER_RECONCILIATION->value,
            ]);
        } elseif ($isStaff && !$isAdmin) {
            $exportQuery->whereNotIn('dataset_key', [
                ExportDatasetKey::CASH_COLLECTIONS->value,
                ExportDatasetKey::CASHIER_RECONCILIATION->value,
            ]);
        }

        $readyExports = (clone $exportQuery)->where('status', ExportJobStatus::READY->value)->count();
        $runningExports = (clone $exportQuery)->whereIn('status', [
            ExportJobStatus::QUEUED->value,
            ExportJobStatus::RUNNING->value,
        ])->count();
        $failedExports = (clone $exportQuery)->where('status', ExportJobStatus::FAILED->value)->count();
        $expiringExports = (clone $exportQuery)
            ->where('status', ExportJobStatus::READY->value)
            ->where('expires_at', '<=', now()->addDays(2))
            ->where('expires_at', '>', now())
            ->count();

        $auditedDownloads = ExportJobEvent::where('event', 'DOWNLOADED')
            ->when(!$isAdmin && $isAccounts, function ($q) {
                $q->whereHas('exportJob', function ($eq) {
                    $eq->whereIn('dataset_key', [
                        ExportDatasetKey::INVOICE_LEDGER->value,
                        ExportDatasetKey::CASH_COLLECTIONS->value,
                        ExportDatasetKey::CASHIER_RECONCILIATION->value,
                    ]);
                });
            })
            ->count();

        return response()->json([
            'data' => [
                'total_imports' => $totalImports,
                'needs_review_imports' => $needsReviewImports,
                'running_jobs' => $runningImports + $runningExports,
                'completed_exports' => $readyExports,
                'ready_exports' => $readyExports,
                'expiring_soon_exports' => $expiringExports,
                'failed_exports' => $failedExports,
                'audited_downloads' => $auditedDownloads,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'role' => $isAdmin ? 'ADMIN' : ($isStaff ? 'OFFICE_STAFF' : 'ACCOUNTS'),
            ],
        ]);
    }

    /**
     * List import/export jobs with role-scoped filtering and pagination (F01).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('ADMIN');
        $isStaff = $user->hasRole('OFFICE_STAFF');
        $isAccounts = $user->hasRole('ACCOUNTS');

        $type = strtoupper($request->query('type', 'ALL')); // ALL, IMPORT, EXPORT
        $status = $request->query('status');
        $search = $request->query('search');
        $pageSize = min(100, max(1, (int) $request->query('page_size', 15)));
        $page = max(1, (int) $request->query('page', 1));

        $jobs = collect();

        // 1. Gather Imports if permitted
        if ($type === 'ALL' || $type === 'IMPORT') {
            if ($isAdmin || $isStaff) {
                $importQuery = ImportJob::with('requester:id,name_bn,name_en,username');

                if ($isStaff && !$isAdmin) {
                    $importQuery->where('requester_id', $user->id);
                }

                if (!empty($status)) {
                    $importQuery->where('state', $status);
                }

                if (!empty($search)) {
                    $importQuery->where(function ($q) use ($search) {
                        $q->where('job_number', 'ilike', "%{$search}%")
                            ->orWhere('source_name', 'ilike', "%{$search}%");
                    });
                }

                $imports = $importQuery->orderByDesc('id')->get()->map(function (ImportJob $job) {
                    return [
                        'id' => $job->id,
                        'job_number' => $job->job_number,
                        'kind' => 'IMPORT',
                        'type_label_bn' => match ($job->mode?->value ?? $job->mode) {
                            'CREATE_ONLY' => 'নতুন শিক্ষার্থী',
                            'MATCH_UPDATE' => 'বিদ্যমান শিক্ষার্থী',
                            'ENROLL_ONLY' => 'ভর্তি তথ্য',
                            default => 'ইমপোর্ট',
                        },
                        'type_label_en' => match ($job->mode?->value ?? $job->mode) {
                            'CREATE_ONLY' => 'New Student',
                            'MATCH_UPDATE' => 'Existing Student',
                            'ENROLL_ONLY' => 'Enrollment Stage',
                            default => 'Import',
                        },
                        'source_or_dataset' => $job->source_name,
                        'mode' => $job->mode?->value ?? $job->mode,
                        'status' => $job->state?->value ?? $job->state,
                        'total_rows' => $job->total_rows,
                        'committed_rows' => $job->committed_rows,
                        'failed_rows' => $job->failed_rows,
                        'requested_by' => $job->requester?->name_en ?? $job->requester?->username,
                        'requested_by_id' => $job->requester_id,
                        'created_at' => $job->created_at?->toIso8601String(),
                    ];
                });

                $jobs = $jobs->concat($imports);
            }
        }

        // 2. Gather Exports if permitted
        if ($type === 'ALL' || $type === 'EXPORT') {
            $exportQuery = ExportJob::with('requester:id,name_bn,name_en,username');

            if ($isAccounts && !$isAdmin) {
                $exportQuery->whereIn('dataset_key', [
                    ExportDatasetKey::INVOICE_LEDGER->value,
                    ExportDatasetKey::CASH_COLLECTIONS->value,
                    ExportDatasetKey::CASHIER_RECONCILIATION->value,
                ]);
            } elseif ($isStaff && !$isAdmin) {
                $exportQuery->whereNotIn('dataset_key', [
                    ExportDatasetKey::CASH_COLLECTIONS->value,
                    ExportDatasetKey::CASHIER_RECONCILIATION->value,
                ]);
            }

            if (!empty($status)) {
                $exportQuery->where('status', $status);
            }

            if (!empty($search)) {
                $exportQuery->where(function ($q) use ($search) {
                    $q->where('job_number', 'ilike', "%{$search}%")
                        ->orWhere('dataset_key', 'ilike', "%{$search}%");
                });
            }

            $exports = $exportQuery->orderByDesc('id')->get()->map(function (ExportJob $job) {
                return [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'kind' => 'EXPORT',
                    'type_label_bn' => match ($job->dataset_key?->value ?? $job->dataset_key) {
                        'student_master' => 'শিক্ষার্থী তালিকা',
                        'cash_collections' => 'পেমেন্ট রিপোর্ট',
                        'invoice_ledger' => 'ইনভয়েস খতিয়ান',
                        'academic_enrollments' => 'একাডেমিক এনরোলমেন্ট',
                        'promotion_results' => 'প্রমোশন ফলাফল',
                        default => 'এক্সপোর্ট',
                    },
                    'type_label_en' => match ($job->dataset_key?->value ?? $job->dataset_key) {
                        'student_master' => 'Students Directory',
                        'cash_collections' => 'Cash Collections',
                        'invoice_ledger' => 'Invoice Ledger',
                        'academic_enrollments' => 'Academic Enrollments',
                        'promotion_results' => 'Promotion Results',
                        default => 'Export',
                    },
                    'source_or_dataset' => $job->dataset_key?->value ?? $job->dataset_key,
                    'format' => $job->format?->value ?? $job->format,
                    'status' => $job->status?->value ?? $job->status,
                    'total_rows' => $job->row_count ?? 0,
                    'committed_rows' => $job->row_count ?? 0,
                    'failed_rows' => 0,
                    'requested_by' => $job->requester?->name_en ?? $job->requester?->username,
                    'requested_by_id' => $job->requester_id,
                    'created_at' => $job->created_at?->toIso8601String(),
                ];
            });

            $jobs = $jobs->concat($exports);
        }

        // Sort descending by created_at
        $sorted = $jobs->sortByDesc('created_at')->values();
        $total = $sorted->count();
        $sliced = $sorted->slice(($page - 1) * $pageSize, $pageSize)->values();

        return response()->json([
            'data' => $sliced,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'last_page' => (int) ceil($total / $pageSize),
            ],
        ]);
    }

    /**
     * Show job details by numeric ID or job_number with authorized metadata (F01).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('ADMIN');
        $isStaff = $user->hasRole('OFFICE_STAFF');
        $isAccounts = $user->hasRole('ACCOUNTS');

        // Check if import job
        $importJob = is_numeric($id)
            ? ImportJob::with(['requester:id,name_bn,name_en,username', 'mapping'])->find($id)
            : ImportJob::with(['requester:id,name_bn,name_en,username', 'mapping'])->where('job_number', $id)->first();

        if ($importJob) {
            // Access check: Accounts is strictly forbidden from viewing import jobs (D08-F01-AT02)
            if ($isAccounts && !$isAdmin) {
                throw DataExchangeDomainException::forbidden('ACCESS_DENIED', 'Accountants cannot view student import jobs.');
            }

            if ($isStaff && !$isAdmin && $importJob->requester_id !== $user->id) {
                throw DataExchangeDomainException::forbidden('ACCESS_DENIED', 'You can only view import jobs initiated by your user account.');
            }

            return response()->json([
                'data' => [
                    'id' => $importJob->id,
                    'job_number' => $importJob->job_number,
                    'kind' => 'IMPORT',
                    'mode' => $importJob->mode?->value ?? $importJob->mode,
                    'template_code' => $importJob->template_code,
                    'template_version' => $importJob->template_version,
                    'locale' => $importJob->locale,
                    'source_name' => $importJob->source_name,
                    'sha256' => $importJob->sha256,
                    'byte_size' => $importJob->byte_size,
                    'detected_sheets' => $importJob->detected_sheets_json,
                    'state' => $importJob->state?->value ?? $importJob->state,
                    'mapping_version' => $importJob->mapping_version,
                    'reviewed_version' => $importJob->reviewed_version,
                    'total_rows' => $importJob->total_rows,
                    'ready_rows' => $importJob->ready_rows,
                    'review_rows' => $importJob->review_rows,
                    'invalid_rows' => $importJob->invalid_rows,
                    'committed_rows' => $importJob->committed_rows,
                    'skipped_rows' => $importJob->skipped_rows,
                    'failed_rows' => $importJob->failed_rows,
                    'has_result_file' => !empty($importJob->result_storage_key),
                    'requested_by' => $importJob->requester?->name_en ?? $importJob->requester?->username,
                    'requested_at' => $importJob->requested_at?->toIso8601String(),
                    'completed_at' => $importJob->completed_at?->toIso8601String(),
                    'retention_until' => $importJob->retention_until?->toIso8601String(),
                    'mapping' => $importJob->mapping ? [
                        'sheet' => $importJob->mapping->sheet,
                        'header_row' => $importJob->mapping->header_row,
                        'fields' => $importJob->mapping->mapping_json,
                        'options' => $importJob->mapping->options_json,
                        'version' => $importJob->mapping->version,
                    ] : null,
                ],
            ]);
        }

        // Check if export job
        $exportJob = is_numeric($id)
            ? ExportJob::with(['requester:id,name_bn,name_en,username', 'artifact'])->find($id)
            : ExportJob::with(['requester:id,name_bn,name_en,username', 'artifact'])->where('job_number', $id)->first();

        if ($exportJob) {
            // Access check: Staff cannot see cash collection or cashier reconciliation exports
            if ($isStaff && !$isAdmin && in_array($exportJob->dataset_key?->value ?? $exportJob->dataset_key, [
                ExportDatasetKey::CASH_COLLECTIONS->value,
                ExportDatasetKey::CASHIER_RECONCILIATION->value,
            ], true)) {
                throw DataExchangeDomainException::forbidden('ACCESS_DENIED', 'Staff members cannot view financial cash collection exports.');
            }

            // Access check: Accounts cannot see student master or academic enrollments
            if ($isAccounts && !$isAdmin && !in_array($exportJob->dataset_key?->value ?? $exportJob->dataset_key, [
                ExportDatasetKey::INVOICE_LEDGER->value,
                ExportDatasetKey::CASH_COLLECTIONS->value,
                ExportDatasetKey::CASHIER_RECONCILIATION->value,
            ], true)) {
                throw DataExchangeDomainException::forbidden('ACCESS_DENIED', 'Accounts users cannot view student profile or academic exports.');
            }

            return response()->json([
                'data' => [
                    'id' => $exportJob->id,
                    'job_number' => $exportJob->job_number,
                    'kind' => 'EXPORT',
                    'dataset_key' => $exportJob->dataset_key?->value ?? $exportJob->dataset_key,
                    'format' => $exportJob->format?->value ?? $exportJob->format,
                    'locale' => $exportJob->locale,
                    'status' => $exportJob->status?->value ?? $exportJob->status,
                    'row_count' => $exportJob->row_count,
                    'filters' => $exportJob->filter_json,
                    'columns' => $exportJob->column_json,
                    'expires_at' => $exportJob->expires_at?->toIso8601String(),
                    'completed_at' => $exportJob->completed_at?->toIso8601String(),
                    'is_expired' => $exportJob->expires_at && $exportJob->expires_at->isPast(),
                    'has_artifact' => (bool) $exportJob->artifact,
                    'artifact' => $exportJob->artifact ? [
                        'filename' => $exportJob->artifact->filename,
                        'byte_size' => $exportJob->artifact->byte_size,
                        'mime' => $exportJob->artifact->mime,
                        'expire_at' => $exportJob->artifact->expire_at?->toIso8601String(),
                    ] : null,
                    'requested_by' => $exportJob->requester?->name_en ?? $exportJob->requester?->username,
                    'created_at' => $exportJob->created_at?->toIso8601String(),
                ],
            ]);
        }

        throw DataExchangeDomainException::notFound('DATA_JOB_NOT_FOUND', "Data job '{$id}' not found.");
    }

    /**
     * Get paginated job events (F09).
     */
    public function events(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('ADMIN');

        $importJob = is_numeric($id) ? ImportJob::find($id) : ImportJob::where('job_number', $id)->first();
        if ($importJob) {
            if (!$isAdmin && $user->hasRole('ACCOUNTS')) {
                throw DataExchangeDomainException::forbidden('ACCESS_DENIED', 'Forbidden.');
            }

            $events = ImportJobEvent::with('actor:id,name_bn,name_en,username')
                ->where('import_job_id', $importJob->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (ImportJobEvent $ev) => [
                    'id' => $ev->id,
                    'event' => $ev->event,
                    'before_state' => $ev->before_state,
                    'after_state' => $ev->after_state,
                    'reason_code' => $ev->reason_code,
                    'actor' => $ev->actor?->name_en ?? $ev->actor?->username,
                    'created_at' => $ev->created_at?->toIso8601String(),
                ]);

            return response()->json(['data' => $events]);
        }

        $exportJob = is_numeric($id) ? ExportJob::find($id) : ExportJob::where('job_number', $id)->first();
        if ($exportJob) {
            $events = ExportJobEvent::with('actor:id,name_bn,name_en,username')
                ->where('export_job_id', $exportJob->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (ExportJobEvent $ev) => [
                    'id' => $ev->id,
                    'event' => $ev->event,
                    'actor' => $ev->actor?->name_en ?? $ev->actor?->username,
                    'created_at' => $ev->created_at?->toIso8601String(),
                ]);

            return response()->json(['data' => $events]);
        }

        throw DataExchangeDomainException::notFound('DATA_JOB_NOT_FOUND', "Data job '{$id}' not found.");
    }

    /**
     * Admin-only cross-actor audit log (F09, D08-F09-AT02).
     */
    public function audit(Request $request): JsonResponse
    {
        $limit = min(100, max(10, (int) $request->query('limit', 25)));

        $importEvents = ImportJobEvent::with(['actor:id,name_en,username', 'importJob:id,job_number,source_name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($ev) => [
                'id' => 'IMP-EV-' . $ev->id,
                'type' => 'IMPORT',
                'job_number' => $ev->importJob?->job_number,
                'event' => $ev->event,
                'actor' => $ev->actor?->name_en ?? $ev->actor?->username,
                'actor_id' => $ev->actor_id,
                'created_at' => $ev->created_at?->toIso8601String(),
            ]);

        $exportEvents = ExportJobEvent::with(['actor:id,name_en,username', 'exportJob:id,job_number,dataset_key'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($ev) => [
                'id' => 'EXP-EV-' . $ev->id,
                'type' => 'EXPORT',
                'job_number' => $ev->exportJob?->job_number,
                'event' => $ev->event,
                'actor' => $ev->actor?->name_en ?? $ev->actor?->username,
                'actor_id' => $ev->actor_id,
                'created_at' => $ev->created_at?->toIso8601String(),
            ]);

        $combined = $importEvents->concat($exportEvents)->sortByDesc('created_at')->values()->slice(0, $limit);

        return response()->json(['data' => $combined]);
    }
}
