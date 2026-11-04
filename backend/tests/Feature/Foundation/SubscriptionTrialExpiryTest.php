<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->admin = foundationUser();
    $this->business = $this->admin->business;
    $this->business->forceFill(['license_token' => null, 'license_revision' => 0, 'trial_uses' => 0])->save();
    $this->customer = Customer::query()->create([
        'business_id' => $this->business->id, 'code' => 'TRIAL-CUSTOMER',
        'first_name' => 'Trial', 'last_name' => 'Customer', 'status' => 'ACTIVE',
        'registration_date' => now()->toDateString(),
    ]);
});

function trialBooking($test, string $code): Booking
{
    return Booking::query()->create([
        'business_id' => $test->business->id, 'branch_id' => $test->admin->default_branch_id,
        'customer_id' => $test->customer->id, 'customer_type' => 'NEW',
        'code' => $code, 'booking_date' => now()->toDateString(), 'booking_time' => '10:00:00',
        'booking_source' => 'RESERVATION', 'status' => 'BOOKED', 'created_by' => $test->admin->id,
    ]);
}

function trialSale($test, string $code, ?Booking $booking = null): Sale
{
    return Sale::query()->create([
        'business_id' => $test->business->id, 'branch_id' => $test->admin->default_branch_id,
        'customer_id' => $test->customer->id, 'code' => $code, 'booking_id' => $booking?->id,
        'sale_date' => now(), 'sale_channel' => 'RETAIL_POS', 'status' => 'FOR_CHECKOUT',
        'created_by' => $test->admin->id,
    ]);
}

test('Premium trial counts a booking once and standalone sales share the same allowance', function () {
    $booking = trialBooking($this, 'TRIAL-BOOKING-1');
    trialSale($this, 'TRIAL-LINKED-SALE', $booking);
    trialSale($this, 'TRIAL-STANDALONE-SALE');
    expect($this->business->fresh()->trial_uses)->toBe(2);
    $booking->update(['status' => 'CANCELLED']);
    expect($this->business->fresh()->trial_uses)->toBe(2);
});

test('50th activity succeeds and 51st is rejected without inserting a record or consuming more', function () {
    $this->business->forceFill(['trial_uses' => 49])->save();
    trialSale($this, 'TRIAL-FIFTIETH');
    expect($this->business->fresh()->trial_uses)->toBe(50);
    try {
        trialBooking($this, 'TRIAL-FIFTY-FIRST');
        $this->fail('Expected the trial limit to reject a new booking.');
    } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(403);
    }
    expect(Booking::query()->where('code', 'TRIAL-FIFTY-FIRST')->exists())->toBeFalse()
        ->and($this->business->fresh()->trial_uses)->toBe(50);
});

test('failed inserts and rolled back transactions do not consume trial quota', function () {
    trialSale($this, 'TRIAL-DUPLICATE');
    try { trialSale($this, 'TRIAL-DUPLICATE'); }
    catch (\Illuminate\Database\QueryException) {}
    expect($this->business->fresh()->trial_uses)->toBe(1);
    try {
        DB::transaction(function () {
            trialBooking($this, 'TRIAL-ROLLBACK');
            throw new RuntimeException('Simulate a failed workflow.');
        });
    } catch (RuntimeException) {}
    expect($this->business->fresh()->trial_uses)->toBe(1)
        ->and(Booking::query()->where('code', 'TRIAL-ROLLBACK')->exists())->toBeFalse();
});

test('trial exhaustion preserves read access blocks writes and permits activation', function () {
    $this->business->forceFill(['trial_uses' => 50])->save();
    $this->admin->unsetRelation('business');
    $this->actingAs($this->admin)->getJson('/api/v1/operations/customers')->assertOk();
    $this->postJson('/api/v1/operations/bookings', [])->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('subscription.trial_remaining', 0);
    $token = subscriptionTestCode($this->business, 'standard', 1);
    $this->postJson('/api/v1/subscription/activate', ['activation_code' => $token])->assertOk();
    $this->admin->unsetRelation('business');
    trialSale($this, 'AFTER-ACTIVATION');
    expect($this->business->fresh()->trial_uses)->toBe(50);
});

test('license expires at its signed timestamp and can be renewed with a higher revision', function () {
    $expiry = now()->addMinute()->timestamp;
    $token = subscriptionTestCode($this->business, 'premium', 1, ['expires_at' => $expiry]);
    $this->actingAs($this->admin)->postJson('/api/v1/subscription/activate', ['activation_code' => $token])->assertOk();
    $this->travel(2)->minutes();
    $this->admin->unsetRelation('business');
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('subscription.status', 'expired');
    $this->getJson('/api/v1/operations/customers')->assertOk();
    $this->postJson('/api/v1/admin/business-setup/branches', [])->assertForbidden();
    $renewed = subscriptionTestCode($this->business, 'premium', 2, ['expires_at' => now()->addYear()->timestamp]);
    $this->postJson('/api/v1/subscription/activate', ['activation_code' => $renewed])->assertOk()->assertJsonPath('subscription.status', 'active');
    $this->travelBack();
});

test('expired codes cannot be activated and direct tier or quota changes are not mass assignable', function () {
    $expired = subscriptionTestCode($this->business, 'premium', 1, ['expires_at' => now()->subSecond()->timestamp]);
    $this->actingAs($this->admin)->postJson('/api/v1/subscription/activate', ['activation_code' => $expired])->assertUnprocessable();
    $this->business->fill(['trial_uses' => 0, 'license_token' => $expired, 'license_revision' => 999]);
    expect($this->business->isDirty('license_token'))->toBeFalse()
        ->and($this->business->isDirty('license_revision'))->toBeFalse();
});

test('license expiry and trial consumption are scoped to one business', function () {
    trialBooking($this, 'TENANT-ONE');
    $other = Business::query()->create(['code' => 'OTHER', 'name' => 'Other business', 'status' => 'ACTIVE', 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
    expect($other->fresh()->trial_uses)->toBe(0)
        ->and($other->license_installation_id)->not->toBe($this->business->license_installation_id)
        ->and(app(SubscriptionService::class)->snapshot($other)['trial_remaining'])->toBe(50);
});
