<?php

namespace App\Services\Foundation;

use App\Models\CodeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CodeSequenceService
{
    public function next(
        string $sequenceKey,
        string $prefix,
        int $padLength = 4,
        ?int $businessId = null,
    ): string {
        return DB::transaction(function () use ($sequenceKey, $prefix, $padLength, $businessId) {
            $now = now();

            CodeSequence::query()->insertOrIgnore([
                'business_id' => $businessId,
                'sequence_key' => $sequenceKey,
                'prefix' => $prefix,
                'next_number' => 1,
                'pad_length' => $padLength,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = CodeSequence::query()
                ->where('sequence_key', $sequenceKey)
                ->lockForUpdate()
                ->firstOrFail();

            $number = $sequence->next_number;

            $sequence->forceFill([
                'prefix' => $prefix,
                'pad_length' => $padLength,
                'next_number' => $number + 1,
            ])->save();

            return $prefix.str_pad((string) $number, $padLength, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Return the next sequence code that is not already taken.
     *
     * This is intentionally separate from next() so callers with an external
     * unique constraint can recover safely when a transactional sequence was
     * reset while historical rows were retained.
     */
    public function nextAvailable(
        string $sequenceKey,
        string $prefix,
        callable $isTaken,
        int $padLength = 4,
        ?int $businessId = null,
        int $maxAttempts = 100,
    ): string {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = $this->next($sequenceKey, $prefix, $padLength, $businessId);

            if (! $isTaken($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique code after '.$maxAttempts.' attempts.');
    }
}
