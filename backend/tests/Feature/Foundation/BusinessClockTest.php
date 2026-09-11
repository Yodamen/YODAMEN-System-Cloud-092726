<?php

use App\Services\Foundation\BusinessClock;
use Carbon\CarbonImmutable;

test('business date uses Asia Manila across the UTC date boundary', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 16:30:00', 'UTC'));

    $clock = app(BusinessClock::class);

    expect($clock->timezone())->toBe('Asia/Manila')
        ->and($clock->today())->toBe('2026-09-11');

    CarbonImmutable::setTestNow();
});
