<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentSlip;
use App\Services\Payments\SlipVerificationTokenService;
use Illuminate\Http\JsonResponse;

class PublicSlipVerificationController extends Controller
{
    public function __construct(
        protected SlipVerificationTokenService $tokenService
    ) {}

    /**
     * Public QR verification endpoint.
     * Respects student privacy: masks name and omits PII/IDs.
     */
    public function verify(string $token): JsonResponse
    {
        $hash = $this->tokenService->hashToken($token);

        /** @var PaymentSlip|null $slip */
        $slip = PaymentSlip::with('student')->where('verification_token_hash', $hash)->first();

        if (!$slip) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_VERIFICATION_TOKEN',
                    'message' => 'The verification token is invalid or expired.',
                ],
            ], 404);
        }

        $maskedNameBn = $this->tokenService->maskName($slip->student?->name_bn);
        $maskedNameEn = $this->tokenService->maskName($slip->student?->name_en);

        return response()->json([
            'success' => true,
            'data' => [
                'type' => 'PAYMENT_SLIP',
                'number' => $slip->slip_number,
                'status' => $slip->status->value,
                'student_code' => $slip->student?->student_code,
                'student_name_bn' => $maskedNameBn,
                'student_name_en' => $maskedNameEn,
                'slip_amount' => (string) $slip->slip_amount,
                'collected_amount' => (string) $slip->collected_amount,
                'remaining_amount' => (string) $slip->remaining_amount,
                'issued_at' => $slip->issued_at?->toDateString(),
                'expires_at' => $slip->expires_at?->toDateString(),
                'is_valid' => in_array($slip->status->value, ['UNPAID', 'PARTIALLY_COLLECTED']),
            ],
        ]);
    }
}

