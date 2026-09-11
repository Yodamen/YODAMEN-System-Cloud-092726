<?php

use App\Services\Foundation\CodeSequenceService;
use App\Services\Foundation\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
});

test('code generator increments durable human readable codes', function () {
    $service = app(CodeSequenceService::class);

    expect($service->next('booking', 'BKG-'))->toBe('BKG-0001')
        ->and($service->next('booking', 'BKG-'))->toBe('BKG-0002');
});

test('idempotency service replays a completed result without running the action twice', function () {
    $service = app(IdempotencyService::class);
    $calls = 0;

    $first = $service->execute('test.checkout', 'idem-001', ['amount' => 100], function () use (&$calls) {
        $calls++;
        return ['status' => 200, 'body' => ['ok' => true]];
    });

    $second = $service->execute('test.checkout', 'idem-001', ['amount' => 100], function () use (&$calls) {
        $calls++;
        return ['status' => 200, 'body' => ['ok' => true]];
    });

    expect($calls)->toBe(1)
        ->and($first['replayed'])->toBeFalse()
        ->and($second['replayed'])->toBeTrue();
});
