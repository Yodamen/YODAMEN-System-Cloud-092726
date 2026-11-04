<?php

namespace App\Services\Subscriptions;

use App\Models\Booking;
use App\Models\Business;
use Illuminate\Http\Exceptions\HttpResponseException;

class TrialUsageService
{
    /** Called inside the transaction that inserts the booking or sale. */
    public function reserve(int $businessId, ?int $bookingId = null): bool
    {
        $business = Business::query()->lockForUpdate()->findOrFail($businessId);
        $subscription = app(SubscriptionService::class)->snapshot($business);
        if ($subscription['status'] === 'active') {
            return false;
        }

        // A booking and its subsequent sale are one trial activity. The
        // business lock serializes all booking and sale reservations together.
        if (in_array($subscription['status'], ['trial', 'trial_exhausted'], true) && $bookingId
            && Booking::query()->whereKey($bookingId)->where('business_id', $businessId)->where('trial_counted', true)->exists()) {
            return true;
        }
        if ($subscription['is_read_only']) {
            throw new HttpResponseException(response()->json([
                'message' => 'Your trial or license has ended. Activate a valid license to continue. Existing records are preserved.',
                'code' => 'SUBSCRIPTION_READ_ONLY',
            ], 403));
        }

        $business->forceFill(['trial_uses' => $business->trial_uses + 1])->save();

        return true;
    }
}
