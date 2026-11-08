<?php

namespace App\Services\DataExchange;

use App\Enums\ImportJobStatus;
use App\Enums\ImportRowAction;
use App\Enums\ImportRowStatus;
use App\Exceptions\DataExchangeDomainException;
use App\Models\ImportJob;
use App\Models\ImportJobEvent;
use App\Models\ImportRow;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ImportResolutionService
{
    /**
     * Submit row-level resolution decisions (F05, F06).
     */
    public function saveResolutions(ImportJob $job, array $resolutions, User $actor): array
    {
        if (in_array($job->state, [ImportJobStatus::COMPLETED, ImportJobStatus::CANCELLED], true)) {
            throw DataExchangeDomainException::workflowConflict("Cannot modify resolutions for a {$job->state->value} import job.");
        }

        $canOverride = $actor->hasRole('ADMIN') || $actor->hasPermission('students.review_duplicates');

        DB::transaction(function () use ($job, $resolutions, $actor, $canOverride) {
            foreach ($resolutions as $res) {
                $rowId = $res['row_id'] ?? null;
                $decision = strtoupper($res['decision'] ?? '');
                $notes = $res['notes'] ?? null;

                $row = ImportRow::where('import_job_id', $job->id)->where('id', $rowId)->firstOrFail();

                if ($decision === 'OVERRIDE_CREATE') {
                    if (!$canOverride) {
                        throw DataExchangeDomainException::forbidden('Admin or duplicate review privilege is required to override duplicate student creation.');
                    }
                    $row->status = ImportRowStatus::READY;
                    $row->action = ImportRowAction::CREATE;
                    $row->decision_json = [
                        'decision' => 'OVERRIDE_CREATE',
                        'actor_id' => $actor->id,
                        'decided_at' => now()->toIso8601String(),
                        'notes' => $notes,
                    ];
                } elseif ($decision === 'SKIP') {
                    $row->status = ImportRowStatus::SKIPPED;
                    $row->decision_json = [
                        'decision' => 'SKIP',
                        'actor_id' => $actor->id,
                        'decided_at' => now()->toIso8601String(),
                        'notes' => $notes,
                    ];
                } elseif ($decision === 'LINK_EXISTING') {
                    $targetStudentId = $res['target_student_id'] ?? null;
                    if (!$targetStudentId) {
                        throw DataExchangeDomainException::schemaViolation('target_student_id is required when linking to an existing student.');
                    }
                    $targetStudent = Student::where('student_id', $targetStudentId)->first();
                    if (!$targetStudent) {
                        throw DataExchangeDomainException::schemaViolation("Student ID '{$targetStudentId}' not found.");
                    }
                    $row->status = ImportRowStatus::READY;
                    $row->action = ImportRowAction::MATCH_UPDATE;
                    $row->target_student_pk = $targetStudent->id;
                    $row->decision_json = [
                        'decision' => 'LINK_EXISTING',
                        'target_student_id' => $targetStudentId,
                        'target_student_pk' => $targetStudent->id,
                        'actor_id' => $actor->id,
                        'decided_at' => now()->toIso8601String(),
                        'notes' => $notes,
                    ];
                } elseif ($decision === 'ACCEPT_UPDATE') {
                    $row->status = ImportRowStatus::READY;
                    $row->decision_json = [
                        'decision' => 'ACCEPT_UPDATE',
                        'field_decisions' => $res['field_decisions'] ?? null,
                        'actor_id' => $actor->id,
                        'decided_at' => now()->toIso8601String(),
                        'notes' => $notes,
                    ];
                } else {
                    throw DataExchangeDomainException::schemaViolation("Invalid decision '{$decision}'. Must be SKIP, LINK_EXISTING, OVERRIDE_CREATE, or ACCEPT_UPDATE.");
                }

                $row->save();
            }

            // Recalculate counters
            $totalRows = ImportRow::where('import_job_id', $job->id)->count();
            $readyRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::READY)->count();
            $reviewRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::REVIEW_REQUIRED)->count();
            $invalidRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::INVALID)->count();
            $skippedRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::SKIPPED)->count();

            $job->reviewed_version = $job->reviewed_version + 1;
            $newToken = hash_hmac('sha256', "{$job->sha256}:{$job->mapping_version}:{$job->reviewed_version}:{$totalRows}", config('app.key'));

            $nextState = ($invalidRows > 0 || $reviewRows > 0)
                ? ImportJobStatus::REVIEW_REQUIRED
                : ImportJobStatus::READY;

            $job->update([
                'total_rows' => $totalRows,
                'ready_rows' => $readyRows,
                'review_rows' => $reviewRows,
                'invalid_rows' => $invalidRows,
                'skipped_rows' => $skippedRows,
                'preview_token_hash' => $newToken,
                'preview_expires_at' => now()->addHours(24),
                'state' => $nextState,
            ]);

            ImportJobEvent::create([
                'import_job_id' => $job->id,
                'actor_id' => $actor->id,
                'event' => 'RESOLUTIONS_SAVED',
                'before_state' => $job->state->value,
                'after_state' => $nextState->value,
                'reason_code' => 'RESOLUTIONS_VERSION_' . $job->reviewed_version,
                'context_redacted_json' => [
                    'reviewed_count' => count($resolutions),
                    'ready_rows' => $readyRows,
                    'review_rows' => $reviewRows,
                    'preview_token' => $newToken,
                ],
                'created_at' => now(),
            ]);
        });

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'state' => $job->state->value,
            'reviewed_version' => $job->reviewed_version,
            'ready_rows' => $job->ready_rows,
            'review_rows' => $job->review_rows,
            'invalid_rows' => $job->invalid_rows,
            'skipped_rows' => $job->skipped_rows,
            'preview_token' => $job->preview_token_hash,
            'preview_expires_at' => $job->preview_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Revalidate import rows and recalculate preview token (F05, F06).
     */
    public function revalidate(ImportJob $job, int $actorId): array
    {
        $totalRows = ImportRow::where('import_job_id', $job->id)->count();
        $readyRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::READY)->count();
        $reviewRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::REVIEW_REQUIRED)->count();
        $invalidRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::INVALID)->count();
        $skippedRows = ImportRow::where('import_job_id', $job->id)->where('status', ImportRowStatus::SKIPPED)->count();

        $nextState = ($invalidRows > 0 || $reviewRows > 0)
            ? ImportJobStatus::REVIEW_REQUIRED
            : ImportJobStatus::READY;

        $newToken = hash_hmac('sha256', "{$job->sha256}:{$job->mapping_version}:{$job->reviewed_version}:{$totalRows}", config('app.key'));

        $job->update([
            'ready_rows' => $readyRows,
            'review_rows' => $reviewRows,
            'invalid_rows' => $invalidRows,
            'skipped_rows' => $skippedRows,
            'preview_token_hash' => $newToken,
            'preview_expires_at' => now()->addHours(24),
            'state' => $nextState,
        ]);

        ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $actorId,
            'event' => 'REVALIDATED',
            'before_state' => $job->state->value,
            'after_state' => $nextState->value,
            'reason_code' => 'REVALIDATION_COMPLETED',
            'context_redacted_json' => [
                'ready_rows' => $readyRows,
                'review_rows' => $reviewRows,
                'preview_token' => $newToken,
            ],
            'created_at' => now(),
        ]);

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'state' => $job->state->value,
            'ready_rows' => $readyRows,
            'review_rows' => $reviewRows,
            'invalid_rows' => $invalidRows,
            'skipped_rows' => $skippedRows,
            'preview_token' => $newToken,
            'preview_expires_at' => $job->preview_expires_at?->toIso8601String(),
        ];
    }
}
