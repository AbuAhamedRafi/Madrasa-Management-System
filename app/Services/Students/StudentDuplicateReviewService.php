<?php

namespace App\Services\Students;

use App\Enums\DuplicateReviewStatus;
use App\Exceptions\StudentDomainException;
use App\Models\StudentDuplicateReview;
use App\Repositories\AuditLogRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StudentDuplicateReviewService
{
    public function __construct(
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function paginateReviews(?string $status = null, int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        $query = StudentDuplicateReview::with(['submitter', 'approver'])
            ->orderByDesc('id');

        if (!empty($status) && strtoupper($status) !== 'ALL') {
            $query->where('status', strtoupper($status));
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?StudentDuplicateReview
    {
        return StudentDuplicateReview::with(['submitter', 'approver', 'student'])->find($id);
    }

    public function submit(array $proposedPayload, array $candidateMatches, int $submittedBy): StudentDuplicateReview
    {
        $hash = hash('sha256', json_encode($proposedPayload));

        return StudentDuplicateReview::create([
            'proposed_payload_hash' => $hash,
            'candidate_matches' => $candidateMatches,
            'submitted_by' => $submittedBy,
            'status' => DuplicateReviewStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function decide(int $reviewId, string $decisionStr, string $reason, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentDuplicateReview
    {
        if (empty(trim($reason))) {
            throw new StudentDomainException(
                'REASON_REQUIRED',
                'A detailed reason is mandatory for duplicate review decision.',
                422,
                ['reason' => ['Review decision reason is mandatory.']]
            );
        }

        $review = StudentDuplicateReview::findOrFail($reviewId);

        if ($review->status !== DuplicateReviewStatus::PENDING) {
            throw new StudentDomainException(
                'REVIEW_ALREADY_DECIDED',
                "Review #{$review->id} has already been {$review->status->value}.",
                409
            );
        }

        $decision = strtoupper(trim($decisionStr));
        if (in_array($decision, ['APPROVE', 'APPROVED'], true)) {
            $newStatus = DuplicateReviewStatus::APPROVED;
        } elseif (in_array($decision, ['REJECT', 'REJECTED'], true)) {
            $newStatus = DuplicateReviewStatus::REJECTED;
        } else {
            throw new StudentDomainException(
                'INVALID_DECISION',
                "Decision must be either 'APPROVE' or 'REJECT'.",
                422
            );
        }

        $review->status = $newStatus;
        $review->decision_reason = trim($reason);
        $review->approved_by = $actorId;
        $review->save();

        $this->auditLogRepository->log(
            actionCode: 'DUPLICATE_REVIEW_DECIDED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: ['status' => 'PENDING'],
            afterMetadata: [
                'review_id' => $review->id,
                'status' => $newStatus->value,
                'reason' => $reason,
            ],
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: $reason,
            entityType: 'StudentDuplicateReview',
            entityId: $review->id
        );

        return $review->load(['submitter', 'approver']);
    }
}

