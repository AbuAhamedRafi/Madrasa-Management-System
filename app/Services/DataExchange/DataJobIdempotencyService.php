<?php

namespace App\Services\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Models\DataJobIdempotency;
use Illuminate\Support\Facades\DB;

class DataJobIdempotencyService
{
    /**
     * Check if request has an existing idempotency key.
     * If completed, returns the cached replay payload.
     * If in-flight, throws 409 conflict.
     * If new, registers IN_PROGRESS record.
     *
     * @throws DataExchangeDomainException
     */
    public function validateOrStart(int $actorId, string $action, ?string $idempotencyKey, array $payload): ?array
    {
        if (empty($idempotencyKey)) {
            return null;
        }

        $cleanPayload = $payload;
        unset($cleanPayload['idempotency_key']);
        $requestHash = hash('sha256', json_encode($cleanPayload));

        return DB::transaction(function () use ($actorId, $action, $idempotencyKey, $requestHash) {
            $existing = DataJobIdempotency::where('actor_id', $actorId)
                ->where('action', $action)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_hash !== $requestHash) {
                    throw DataExchangeDomainException::idempotentMismatch($idempotencyKey);
                }

                if ($existing->state === 'IN_PROGRESS') {
                    throw DataExchangeDomainException::idempotentInFlight($idempotencyKey);
                }

                if ($existing->state === 'COMPLETED') {
                    return [
                        'is_replay' => true,
                        'status_code' => $existing->response_code ?? 200,
                        'data' => $existing->response_json,
                    ];
                }
            }

            DataJobIdempotency::create([
                'actor_id' => $actorId,
                'action' => $action,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'state' => 'IN_PROGRESS',
                'expires_at' => now()->addHours(24),
            ]);

            return null;
        });
    }

    public function complete(
        int $actorId,
        string $action,
        ?string $idempotencyKey,
        int $statusCode,
        array $responsePayload,
        ?string $resultType = null,
        ?int $resultId = null
    ): void {
        if (empty($idempotencyKey)) {
            return;
        }

        DataJobIdempotency::where('actor_id', $actorId)
            ->where('action', $action)
            ->where('idempotency_key', $idempotencyKey)
            ->update([
                'state' => 'COMPLETED',
                'response_code' => $statusCode,
                'response_json' => $responsePayload,
                'result_type' => $resultType,
                'result_id' => $resultId,
            ]);
    }

    public function fail(int $actorId, string $action, ?string $idempotencyKey, int $statusCode, array $errorPayload): void
    {
        if (empty($idempotencyKey)) {
            return;
        }

        DataJobIdempotency::where('actor_id', $actorId)
            ->where('action', $action)
            ->where('idempotency_key', $idempotencyKey)
            ->update([
                'state' => 'FAILED',
                'response_code' => $statusCode,
                'response_json' => $errorPayload,
            ]);
    }
}
