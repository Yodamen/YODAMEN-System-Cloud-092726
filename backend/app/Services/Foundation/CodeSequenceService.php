<?php

namespace App\Services\Foundation;

use App\Models\CodeSequence;
use Illuminate\Support\Facades\DB;

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
}
