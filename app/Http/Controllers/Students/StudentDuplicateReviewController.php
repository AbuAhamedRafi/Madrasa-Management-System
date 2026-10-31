<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Services\Students\StudentDuplicateReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDuplicateReviewController extends Controller
{
    public function __construct(
        protected StudentDuplicateReviewService $duplicateReviewService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);

        $paginator = $this->duplicateReviewService->paginateReviews($status, $page, $pageSize);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $review = $this->duplicateReviewService->findById($id);
        if (!$review) {
            abort(404, 'Review request not found.');
        }

        return response()->json([
            'data' => $review,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'proposed_payload' => ['required', 'array'],
            'candidate_matches' => ['required', 'array'],
        ]);

        $review = $this->duplicateReviewService->submit(
            proposedPayload: $request->input('proposed_payload'),
            candidateMatches: $request->input('candidate_matches'),
            submittedBy: $request->user()->id
        );

        return response()->json([
            'data' => $review,
        ], 201);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $review = $this->duplicateReviewService->decide(
            reviewId: $id,
            decisionStr: $request->input('decision'),
            reason: $request->input('reason'),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $review,
        ]);
    }
}

