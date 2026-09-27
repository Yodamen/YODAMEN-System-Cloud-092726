<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$backendRoot = dirname(__DIR__);
$migrationName = '2026_09_27_016000_inventory_expense_reversal_controls';
$migrationPath = $backendRoot . '/database/migrations/' . $migrationName . '.php';

if (! is_file($migrationPath)) {
    fwrite(STDERR, "Migration not found: {$migrationPath}\n");
    exit(1);
}

require $backendRoot . '/vendor/autoload.php';
$app = require $backendRoot . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$source = file_get_contents($migrationPath);
if ($source === false) {
    fwrite(STDERR, "Unable to read migration.\n");
    exit(1);
}

preg_match_all('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $tableMatches);
$createdTables = array_values(array_unique($tableMatches[1] ?? []));

$backupPath = $migrationPath . '.before_mysql_identifier_fix.bak';
if (! is_file($backupPath)) {
    if (! copy($migrationPath, $backupPath)) {
        fwrite(STDERR, "Unable to create migration backup.\n");
        exit(1);
    }
    echo "Backup created: {$backupPath}\n";
}

$shortIdentifier = static function (string $prefix, string $table, string $detail): string {
    $clean = static fn (string $value): string => trim((string) preg_replace('/[^a-z0-9]+/i', '_', strtolower($value)), '_');
    $tablePart = substr($clean($table), 0, 18);
    $detailPart = substr($clean($detail), 0, 18);
    $hash = substr(hash('sha256', $prefix . '|' . $table . '|' . $detail), 0, 8);

    return substr($prefix . '_' . $tablePart . '_' . $detailPart . '_' . $hash, 0, 63);
};

$blockPattern = '/(Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*void\s*)?\{)(.*?)(\n\s*\}\s*\);)/s';

$patched = preg_replace_callback($blockPattern, function (array $block) use ($shortIdentifier): string {
    $table = $block[2];
    $body = $block[3];

    // foreignId(...)->constrained(...) with an automatically generated FK name.
    $body = preg_replace_callback(
        '/(\$table->foreignId\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)(?:(?!;).)*?->constrained\()([^)]*)(\))/s',
        function (array $match) use ($table, $shortIdentifier): string {
            $args = trim($match[3]);
            if (str_contains($args, 'indexName:')) {
                return $match[0];
            }

            $name = $shortIdentifier('fk', $table, $match[2]);
            $newArgs = $args === '' ? "indexName: '{$name}'" : $args . ", indexName: '{$name}'";

            return $match[1] . $newArgs . $match[4];
        },
        $body
    );

    // Explicit foreign(...) calls without a supplied constraint name.
    $body = preg_replace_callback(
        '/\$table->foreign\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)(?!\s*,)/',
        function (array $match) use ($table, $shortIdentifier): string {
            $name = $shortIdentifier('fk', $table, $match[1]);
            return "\$table->foreign('{$match[1]}', '{$name}')";
        },
        $body
    );

    // Give array indexes explicit short names to avoid the same MySQL 64-char limit later.
    foreach (['index' => 'ix', 'unique' => 'uq'] as $method => $prefix) {
        $pattern = '/\$table->' . $method . '\(\s*(\[[^\]]+\])\s*\)/s';
        $body = preg_replace_callback($pattern, function (array $match) use ($table, $shortIdentifier, $method, $prefix): string {
            preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $match[1], $cols);
            $detail = implode('_', $cols[1] ?? []);
            $name = $shortIdentifier($prefix, $table, $detail ?: $method);
            return "\$table->{$method}({$match[1]}, '{$name}')";
        }, $body);
    }

    return $block[1] . $body . $block[4];
}, $source);

if (! is_string($patched)) {
    fwrite(STDERR, "Unable to patch migration source.\n");
    exit(1);
}

if ($patched !== $source) {
    if (file_put_contents($migrationPath, $patched) === false) {
        fwrite(STDERR, "Unable to write patched migration.\n");
        exit(1);
    }
    echo "Patched long FK/index identifiers in {$migrationName}.php\n";
} else {
    echo "Migration already appears patched; no identifier changes were needed.\n";
}

$alreadyRecorded = DB::table('migrations')->where('migration', $migrationName)->exists();
if ($alreadyRecorded) {
    echo "Migration is already recorded as complete. No partial tables were dropped.\n";
    exit(0);
}

if ($createdTables !== []) {
    Schema::disableForeignKeyConstraints();
    try {
        foreach (array_reverse($createdTables) as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
                echo "Dropped partial table: {$table}\n";
            }
        }
    } finally {
        Schema::enableForeignKeyConstraints();
    }
}

echo "Repair complete. Run: php artisan migrate\n";
