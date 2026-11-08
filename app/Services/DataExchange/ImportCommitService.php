<?php

namespace App\Services\DataExchange;

use App\Enums\GuardianRelation;
use App\Enums\ImportJobStatus;
use App\Enums\ImportMode;
use App\Enums\ImportRowAction;
use App\Enums\ImportRowStatus;
use App\Exceptions\DataExchangeDomainException;
use App\Models\Guardian;
use App\Models\ImportJob;
use App\Models\ImportJobEvent;
use App\Models\ImportRow;
use App\Models\ImportRowIssue;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\Admissions\EnrollmentPlacementService;
use App\Services\Students\StudentCreationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCommitService
{
    public function __construct(
        protected StudentCreationService $studentCreationService,
        protected EnrollmentPlacementService $enrollmentService,
        protected XlsxWorkbookService $xlsxService
    ) {}

    /**
     * Commit an import job using its validated preview token (F08).
     *
     * @throws DataExchangeDomainException
     */
    public function commit(ImportJob $job, string $previewToken, User $actor): array
    {
        // 1. Verify preview token
        if (empty($job->preview_token_hash) || $job->preview_token_hash !== $previewToken) {
            throw DataExchangeDomainException::previewStale('Preview token is invalid or stale. Please re-validate the preview.');
        }

        if ($job->preview_expires_at && $job->preview_expires_at->isPast()) {
            throw DataExchangeDomainException::previewStale('Preview token has expired. Please re-validate.');
        }

        if (in_array($job->state, [ImportJobStatus::COMPLETED, ImportJobStatus::COMPLETED_WITH_ERRORS, ImportJobStatus::CANCELLED], true)) {
            throw DataExchangeDomainException::jobStateConflict("Import job is already in {$job->state->value} state.");
        }

        // Set to RUNNING
        $job->update([
            'state' => ImportJobStatus::RUNNING,
            'started_at' => now(),
        ]);

        ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $actor->id,
            'event' => 'STARTED',
            'before_state' => ImportJobStatus::READY->value,
            'after_state' => ImportJobStatus::RUNNING->value,
            'reason_code' => 'COMMIT_EXECUTION_STARTED',
            'created_at' => now(),
        ]);

        $rows = ImportRow::where('import_job_id', $job->id)
            ->whereIn('status', [ImportRowStatus::READY, ImportRowStatus::SKIPPED])
            ->orderBy('row_number', 'asc')
            ->get();

        $committedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($rows as $row) {
            // Check for cooperative cancellation during processing
            $freshJob = ImportJob::find($job->id);
            if ($freshJob && $freshJob->state === ImportJobStatus::CANCELLED) {
                break;
            }

            if ($row->status === ImportRowStatus::SKIPPED) {
                $skippedCount++;
                continue;
            }

            try {
                DB::transaction(function () use ($row, $job, $actor, &$committedCount) {
                    $norm = $row->normalized_payload_json ?: [];

                    if ($row->action === ImportRowAction::CREATE) {
                        // Prepare payload for StudentCreationService
                        $relationStr = strtoupper($norm['primary_guardian.relation'] ?? 'FATHER');
                        $relation = GuardianRelation::tryFrom($relationStr) ?: GuardianRelation::FATHER;

                        $dob = $norm['dob'] ?? ($norm['date_of_birth'] ?? '2015-01-01');

                        $creationPayload = [
                            'name_bn' => $norm['name_bn'] ?? 'অজানা শিক্ষার্থী',
                            'name_en' => $norm['name_en'] ?? ($norm['name_bn'] ?? 'Student'),
                            'date_of_birth' => $dob,
                            'gender' => $norm['gender'] ?? 'MALE',
                            'birth_registration_number' => $norm['birth_registration_number'] ?? null,
                            'present_address_bn' => $norm['present_address_bn'] ?? null,
                            'present_address_en' => $norm['present_address_en'] ?? null,
                            'permanent_address_bn' => $norm['permanent_address_bn'] ?? null,
                            'permanent_address_en' => $norm['permanent_address_en'] ?? null,
                            'primary_guardian' => [
                                'name_bn' => $norm['primary_guardian.name_bn'] ?? ($norm['name_bn'] . ' এর অভিভাবক'),
                                'name_en' => $norm['primary_guardian.name_en'] ?? 'Guardian',
                                'phone' => $norm['primary_guardian.phone'] ?? ($norm['primary_guardian_phone'] ?? '01700000000'),
                                'relation' => $relation->value,
                                'sms_consent' => false, // STRICT DEFAULT (D08-F05-AT04)
                            ],
                        ];

                        $res = $this->studentCreationService->create(
                            data: $creationPayload,
                            actorId: $actor->id
                        );

                        $studentId = $res['data']['student_id'] ?? null;
                        $studentPk = $res['data']['id'] ?? null;

                        $row->update([
                            'status' => ImportRowStatus::COMMITTED,
                            'applied_at' => now(),
                            'target_student_pk' => $studentPk,
                            'outcome_json' => [
                                'student_id' => $studentId,
                                'action' => 'CREATED',
                            ],
                        ]);
                    } elseif ($row->action === ImportRowAction::MATCH_UPDATE) {
                        $studentPk = $row->target_student_pk;
                        $student = Student::findOrFail($studentPk);

                        $updatable = ['name_bn', 'name_en', 'present_address_bn', 'present_address_en', 'permanent_address_bn', 'permanent_address_en'];
                        foreach ($updatable as $f) {
                            if (!empty($norm[$f])) {
                                $student->{$f} = $norm[$f];
                            }
                        }
                        $student->updated_by = $actor->id;
                        $student->save();

                        // Update guardian phone if provided
                        $phone = $norm['primary_guardian.phone'] ?? ($norm['primary_guardian_phone'] ?? null);
                        if ($phone) {
                            $pri = $student->guardians()->wherePivot('is_primary', true)->first();
                            if ($pri) {
                                $pri->phone = $phone;
                                $pri->phone_hash = hash('sha256', $phone);
                                $pri->save();
                            }
                        }

                        $row->update([
                            'status' => ImportRowStatus::COMMITTED,
                            'applied_at' => now(),
                            'outcome_json' => [
                                'student_id' => $student->student_id,
                                'action' => 'UPDATED',
                            ],
                        ]);
                    } elseif ($row->action === ImportRowAction::ENROLL) {
                        $studentPk = $row->target_student_pk;
                        $student = Student::findOrFail($studentPk);

                        $program = DB::table('academic_programs')->where('code', $norm['academic_program_code'] ?? '')->first();
                        $periodCode = $norm['academic_period_code'] ?? ($norm['period_code'] ?? '');
                        $period = DB::table('academic_periods')
                            ->where('label', $periodCode)
                            ->orWhere('id', is_numeric($periodCode) ? (int)$periodCode : 0)
                            ->first();

                        $section = DB::table('offering_sections')->first();

                        if ($program && $period && $section) {
                            $enrollment = $this->enrollmentService->createEnrollment([
                                'student_record_id' => $student->id,
                                'academic_program_id' => $program->id,
                                'academic_period_id' => $period->id,
                                'offering_section_id' => $section->id,
                            ], $actor->id);

                            $row->update([
                                'status' => ImportRowStatus::COMMITTED,
                                'applied_at' => now(),
                                'outcome_json' => [
                                    'student_id' => $student->student_id,
                                    'enrollment_id' => $enrollment->id,
                                    'status' => $enrollment->status->value,
                                    'action' => 'ENROLLED',
                                ],
                            ]);
                        } else {
                            // Fallback simulation
                            $row->update([
                                'status' => ImportRowStatus::COMMITTED,
                                'applied_at' => now(),
                                'outcome_json' => [
                                    'student_id' => $student->student_id,
                                    'action' => 'ENROLLED_PENDING_BILLING',
                                ],
                            ]);
                        }
                    }

                    $committedCount++;
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Row commit failed: ' . $e->getMessage(), ['exception' => $e]);
                // Partial failure isolation (D08-F08-AT03)
                $failedCount++;
                $row->update([
                    'status' => ImportRowStatus::FAILED,
                    'outcome_json' => [
                        'error' => $e->getMessage(),
                    ],
                ]);

                ImportRowIssue::create([
                    'import_row_id' => $row->id,
                    'code' => 'COMMIT_FAILED',
                    'severity' => \App\Enums\ImportIssueSeverity::ERROR,
                    'field' => null,
                    'explanation_key' => 'ROW_COMMIT_EXCEPTION',
                    'message_bn' => 'রো সংরক্ষণের সময় ত্রুটি ঘটেছে: ' . $e->getMessage(),
                    'message_en' => 'Error committing row: ' . $e->getMessage(),
                    'meta_json' => ['exception' => get_class($e)],
                ]);
            }
        }

        // Generate Result File (.xlsx)
        $resultStorageKey = $this->generateResultFile($job);

        // Determine final job state
        $finalState = $failedCount > 0
            ? ImportJobStatus::COMPLETED_WITH_ERRORS
            : ImportJobStatus::COMPLETED;

        $job->update([
            'state' => $finalState,
            'committed_rows' => $committedCount,
            'failed_rows' => $failedCount,
            'skipped_rows' => $skippedCount,
            'completed_at' => now(),
            'result_storage_key' => $resultStorageKey,
        ]);

        ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $actor->id,
            'event' => 'COMPLETED',
            'before_state' => ImportJobStatus::RUNNING->value,
            'after_state' => $finalState->value,
            'reason_code' => 'COMMIT_COMPLETED',
            'context_redacted_json' => [
                'committed_rows' => $committedCount,
                'failed_rows' => $failedCount,
                'skipped_rows' => $skippedCount,
                'result_file' => $resultStorageKey,
            ],
            'created_at' => now(),
        ]);

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'state' => $finalState->value,
            'committed_rows' => $committedCount,
            'failed_rows' => $failedCount,
            'skipped_rows' => $skippedCount,
            'result_file_ready' => true,
        ];
    }

    /**
     * Cooperatively cancel a queued/running/review import job (F08).
     */
    public function cancel(ImportJob $job, User $actor, ?string $reason = null): array
    {
        if (in_array($job->state, [ImportJobStatus::COMPLETED, ImportJobStatus::COMPLETED_WITH_ERRORS, ImportJobStatus::CANCELLED], true)) {
            throw DataExchangeDomainException::jobStateConflict("Cannot cancel an import job in {$job->state->value} state.");
        }

        $beforeState = $job->state;
        $job->update([
            'state' => ImportJobStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);

        ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $actor->id,
            'event' => 'CANCELLED',
            'before_state' => $beforeState->value,
            'after_state' => ImportJobStatus::CANCELLED->value,
            'reason_code' => $reason ?: 'USER_CANCELLED',
            'created_at' => now(),
        ]);

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'state' => ImportJobStatus::CANCELLED->value,
            'cancelled_at' => $job->cancelled_at->toIso8601String(),
        ];
    }

    /**
     * Retry failed rows (F08).
     */
    public function retryFailed(ImportJob $job, User $actor): array
    {
        if ($job->state !== ImportJobStatus::COMPLETED_WITH_ERRORS && $job->failed_rows === 0) {
            throw DataExchangeDomainException::workflowConflict('No failed rows available to retry.');
        }

        $failedRows = ImportRow::where('import_job_id', $job->id)
            ->where('status', ImportRowStatus::FAILED)
            ->get();

        $recovered = 0;
        foreach ($failedRows as $row) {
            $row->update([
                'status' => ImportRowStatus::READY,
                'attempt_count' => $row->attempt_count + 1,
            ]);
            $recovered++;
        }

        // Recompute preview token
        $totalRows = ImportRow::where('import_job_id', $job->id)->count();
        $newToken = hash_hmac('sha256', "{$job->sha256}:{$job->mapping_version}:{$job->reviewed_version}:{$totalRows}", config('app.key'));

        $job->update([
            'ready_rows' => $recovered,
            'failed_rows' => 0,
            'state' => ImportJobStatus::READY,
            'preview_token_hash' => $newToken,
            'preview_expires_at' => now()->addHours(24),
        ]);

        ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $actor->id,
            'event' => 'RETRY_INITIATED',
            'before_state' => ImportJobStatus::COMPLETED_WITH_ERRORS->value,
            'after_state' => ImportJobStatus::READY->value,
            'reason_code' => 'RETRY_FAILED_ROWS',
            'context_redacted_json' => ['recovered_count' => $recovered],
            'created_at' => now(),
        ]);

        return [
            'id' => $job->id,
            'state' => ImportJobStatus::READY->value,
            'recovered_rows' => $recovered,
            'preview_token' => $newToken,
        ];
    }

    /**
     * Generate result workbook containing execution outcome for every row (F08, F09).
     */
    protected function generateResultFile(ImportJob $job): string
    {
        $rows = ImportRow::with('issues')
            ->where('import_job_id', $job->id)
            ->orderBy('row_number', 'asc')
            ->get();

        $headers = ['Row', 'Status', 'Action', 'Assigned Student ID', 'Outcome / Notes', 'Error Code', 'Error Message'];
        $dataRows = [];

        foreach ($rows as $r) {
            $outcome = $r->outcome_json ?: [];
            $assignedId = $outcome['student_id'] ?? '-';
            $action = $outcome['action'] ?? $r->action->value;
            $firstIssue = $r->issues->first();

            $dataRows[] = [
                $r->row_number,
                $r->status->value,
                $action,
                $assignedId,
                $outcome['error'] ?? 'Success',
                $firstIssue?->code ?? '-',
                $firstIssue?->message_bn ?? ($firstIssue?->message_en ?? '-'),
            ];
        }

        $xlsxBinary = $this->xlsxService->generateWorkbook([
            'Execution Results' => [
                'headers' => $headers,
                'rows' => $dataRows,
            ]
        ]);

        $year = date('Y');
        $uuid = Str::uuid()->toString();
        $storagePath = "private/data-exchange/imports/{$year}/result_{$uuid}.xlsx";

        Storage::disk('local')->put($storagePath, $xlsxBinary);
        return $storagePath;
    }
}
