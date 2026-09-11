<?php

namespace App\Services\Foundation;

use Carbon\CarbonImmutable;

class BusinessClock
{
    public function timezone(): string
    {
        return (string) config('yodamen.business_timezone', 'Asia/Manila');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function today(): string
    {
        return $this->now()->toDateString();
    }
}
