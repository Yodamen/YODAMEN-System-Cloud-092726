<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Unique, immutable AP reference for each originating payable document. */
class ApControlNumberService
{
    public function assign(int $businessId, string $sourceType, int $sourceId, string $documentDate): string
    {
        return DB::transaction(function () use ($businessId, $sourceType, $sourceId, $documentDate) {
            // Serialize allocations per business even when the first number for a date does not yet exist.
            if (! DB::table('businesses')->where('id', $businessId)->lockForUpdate()->exists()) {
                throw new RuntimeException('Business not found for AP number allocation.');
            }
            $sourceType = strtoupper($sourceType);
            $existing = DB::table('ap_control_numbers')
                ->where('business_id', $businessId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();
            if ($existing) return $existing->code;

            $date = CarbonImmutable::parse($documentDate)->toDateString();
            $year = (int) substr($date, 0, 4);
            $last = (int) DB::table('ap_control_numbers')
                ->where('business_id', $businessId)
                ->where('document_date', $date)
                ->max('sequence_number');
            $number = $last + 1;
            $code = 'AP-'.CarbonImmutable::parse($date)->format('md').'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            DB::table('ap_control_numbers')->insert([
                'business_id' => $businessId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'document_date' => $date,
                'code_year' => $year,
                'sequence_number' => $number,
                'code' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $code;
        });
    }
}
