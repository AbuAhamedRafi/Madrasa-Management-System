<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentNotification;
use App\Services\Payments\GuardianPaymentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentNotificationController extends Controller
{
    public function __construct(
        protected GuardianPaymentNotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('payments.notifications.read');

        $query = PaymentNotification::with(['student', 'guardian', 'receipt', 'lastRetriedBy'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->query('student_id'));
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 15)));
        $notifications = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function retry(PaymentNotification $notification, Request $request): JsonResponse
    {
        $this->authorize('payments.notifications.retry');

        $retried = $this->notificationService->retryNotification($notification, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Notification retry triggered.',
            'data' => $retried->load(['student', 'guardian']),
        ]);
    }
}

