<?php

namespace App\Services\Academics;

use App\Enums\CohortStatus;
use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicCohort;
use App\Models\AcademicPeriod;
use App\Models\User;
use App\Repositories\Academics\AcademicPeriodRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Support\Facades\DB;

class PeriodLifecycleService
{
    public function __construct(
        protected AcademicPeriodRepository $periodRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function transitionStatus(
        int $periodId,
        PeriodState $targetState,
        int $lockVersion,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicPeriod {
        return DB::transaction(function () use ($periodId, $targetState, $lockVersion, $reason, $actor, $ip, $requestId) {
            $period = AcademicPeriod::where('id', $periodId)->lockForUpdate()->first();
            if (! $period) {
                abort(404, 'Academic period not found.');
            }

            if ($period->lock_version !== $lockVersion) {
                throw new AcademicDomainException(
                    'VERSION_CONFLICT',
                    'Academic period changed. Refresh and retry.',
                    409
                );
            }

            $currentState = $period->state;
            if ($currentState === $targetState) {
                return $period->fresh(['program', 'cohort']);
            }

            if ($currentState === PeriodState::DRAFT && $targetState === PeriodState::CLOSED) {
                throw new AcademicDomainException(
                    'PERIOD_NOT_READY',
                    'A DRAFT period cannot be closed directly before it has been opened.',
                    409
                );
            }

            if ($targetState === PeriodState::OPEN && $currentState === PeriodState::DRAFT) {
                if (! $this->periodRepository->hasActiveOfferingWithSection($period->id)) {
                    throw new AcademicDomainException(
                        'PERIOD_NOT_READY',
                        'Academic period cannot be opened until at least one active offering and active section exist.',
                        409
                    );
                }
            }

            if (
                ($currentState === PeriodState::OPEN && $targetState === PeriodState::CLOSED) ||
                ($currentState === PeriodState::CLOSED && $targetState === PeriodState::OPEN)
            ) {
                if (empty(trim((string) $reason))) {
                    throw new AcademicDomainException(
                        'VALIDATION_ERROR',
                        'A mandatory reason is required to close or reopen an academic period.',
                        422,
                        ['reason' => ['Please provide a reason for closing or reopening this academic period.']]
                    );
                }
            }

            if ($targetState === PeriodState::OPEN && $period->cohort_id !== null) {
                $cohort = AcademicCohort::where('id', $period->cohort_id)->lockForUpdate()->first();
                if ($cohort && $cohort->status === CohortStatus::DRAFT) {
                    $cohort->update(['status' => CohortStatus::ACTIVE]);
                }
            }

            $before = [
                'state' => $currentState->value,
                'lock_version' => $period->lock_version,
            ];

            $period->update([
                'state' => $targetState,
                'lock_version' => $period->lock_version + 1,
            ]);

            $updated = $period->fresh(['program', 'cohort']);

            $actionCode = match (true) {
                $currentState === PeriodState::CLOSED && $targetState === PeriodState::OPEN => 'PERIOD_REOPENED',
                $targetState === PeriodState::CLOSED => 'PERIOD_CLOSED',
                default => 'PERIOD_OPENED',
            };

            $this->auditLogger->record(
                $actionCode,
                $actor?->id,
                null,
                $before,
                [
                    'state' => $updated->state->value,
                    'lock_version' => $updated->lock_version,
                    'reason' => $reason,
                ],
                $ip,
                $requestId,
                $reason,
                'AcademicPeriod',
                $updated->id
            );

            return $updated;
        });
    }
}

