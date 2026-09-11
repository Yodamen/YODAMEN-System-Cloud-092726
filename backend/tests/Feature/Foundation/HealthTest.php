<?php

test('health endpoint remains available', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'system' => 'YODAMEN',
            'backend' => 'Laravel',
            'database' => 'MySQL',
            'timezone' => 'Asia/Manila',
        ]);
});
