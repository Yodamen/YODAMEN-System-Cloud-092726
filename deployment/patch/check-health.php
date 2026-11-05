<?php
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
for ($i = 0; $i < 30; $i++) {
    @file_get_contents('http://127.0.0.1/up', false, $ctx);
    if (str_contains($http_response_header[0] ?? '', '200')) { echo "Application health check passed.\n"; exit(0); }
    sleep(1);
}
fwrite(STDERR, "Application health check failed.\n"); exit(1);
