<?php

namespace App\Services\Billing;

use App\Exceptions\BillingDomainException;
use App\Models\BillingEventInbox;
use Closure;
use Illuminate\Support\Facades\DB;

class BillingIdempotencyService
{
    /**
     * Execute an operation with atomic idempotency key protection.
     *
     * - Same key + same payload hash + COMPLETED => returns cached response
     * - Same key + different payload hash => throws 422 IDEMPOTENCY_PAYLOAD_MISMATCH
     * - Same key + IN_FLIGHT => throws 409 IDEMPOTENCY_IN_FLIGHT
     */
    public function execute(
        ?string $idempotencyKey,
        string $scope,
        array $payload,
        Closure $operation
    ): mixed {
        if (!$idempotencyKey || trim($idempotencyKey) === '') {
            return $operation();
        }

        $eventId = "IDEM:{$scope}:" . trim($idempotencyKey);
        $payloadHash = hash('sha256', json_encode($this->normalizePayload($payload)));

        $record = DB::transaction(function () use ($eventId, $scope, $payloadHash, $idempotencyKey) {
            $existing = BillingEventInbox::where('event_id', $eventId)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw BillingDomainException::idempotentMismatch($idempotencyKey);
                }

                if ($existing->status === 'IN_FLIGHT') {
                    throw BillingDomainException::idempotentInFlight($idempotencyKey);
                }

                if ($existing->status === 'COMPLETED') {
                    return $existing;
                }
            }

            return BillingEventInbox::updateOrCreate(
                ['event_id' => $eventId],
                [
                    'event_type' => 'API_IDEMPOTENCY',
                    'aggregate_type' => $scope,
                    'aggregate_id' => null,
                    'payload_hash' => $payloadHash,
                    'payload_json' => ['request_hash' => $payloadHash],
                    'status' => 'IN_FLIGHT',
                ]
            );
        });

        if ($record->status === 'COMPLETED' && isset($record->payload_json['result'])) {
            return $record->payload_json['result'];
        }

        try {
            $result = $operation();

            $serializedResult = is_object($result) && method_exists($result, 'toArray')
                ? $result->toArray()
                : $result;

            $record->update([
                'status' => 'COMPLETED',
                'processed_at' => now(),
                'payload_json' => [
                    'request_hash' => $payloadHash,
                    'result' => $serializedResult,
                ],
            ]);

            return $result;
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }
    }

    private function normalizePayload(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = $this->normalizePayload($v);
            }
        }
        return $payload;
    }
}
