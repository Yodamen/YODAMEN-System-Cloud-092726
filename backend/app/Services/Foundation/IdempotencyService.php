<?php

namespace App\Services\Foundation;

use App\Models\IdempotencyRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IdempotencyService
{
    /**
     * Execute an action once for a scope + idempotency key.
     * The callback must return ['status' => int, 'body' => array].
     */
    public function execute(
        string $scope,
        string $key,
        array $payload,
        callable $callback,
        ?User $user = null,
        ?int $ttlMinutes = 1440,
    ): array {
        $requestHash = hash('sha256', $this->canonicalJson($payload));

        return DB::transaction(function () use ($scope, $key, $requestHash, $callback, $user, $ttlMinutes) {
            $now = now();

            $inserted = IdempotencyRequest::query()->insertOrIgnore([
                'user_id' => $user?->id,
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => IdempotencyRequest::STATUS_PROCESSING,
                'locked_at' => $now,
                'expires_at' => $ttlMinutes ? $now->copy()->addMinutes($ttlMinutes) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $record = IdempotencyRequest::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($record->request_hash, $requestHash)) {
                throw new DomainException('The idempotency key was already used with different request data.');
            }

            if ($inserted === 0) {
                if ($record->status === IdempotencyRequest::STATUS_COMPLETED) {
                    return [
                        'status' => $record->response_status,
                        'body' => $record->response_body ?? [],
                        'replayed' => true,
                    ];
                }

                throw new RuntimeException('An identical request is already being processed.');
            }

            $result = $callback();

            if (! is_array($result) || ! isset($result['status']) || ! array_key_exists('body', $result)) {
                throw new RuntimeException('Idempotent callback must return status and body.');
            }

            $record->forceFill([
                'status' => IdempotencyRequest::STATUS_COMPLETED,
                'response_status' => (int) $result['status'],
                'response_body' => (array) $result['body'],
                'completed_at' => now(),
            ])->save();

            return [
                'status' => (int) $result['status'],
                'body' => (array) $result['body'],
                'replayed' => false,
            ];
        });
    }

    private function canonicalJson(array $payload): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }

            ksort($value);

            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
