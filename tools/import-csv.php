<?php

declare(strict_types=1);

/**
 * One-time import counterpart to export-access-to-csv.ps1: loads every
 * <table>.csv in a directory into the already-migrated MySQL schema
 * (migrations/schema.sql must already be applied).
 *
 * CLI only, deliberately -- this does bulk INSERTs with FK/unique checks
 * disabled for speed and has no business being reachable over HTTP (the
 * tools/ directory is also blocked by .htaccess, but this is a second,
 * independent guard).
 *
 * Usage: php import-csv.php /path/to/csv_export
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Infrastructure\Config;

// Same table list/order as export-access-to-csv.ps1 and migrations/schema.sql.
// No real foreign keys exist in the source database, so import order doesn't matter.
$tables = [
    'Client', 'clubs', 'dance', 'dancetype', 'DP_blok1_less', 'DP_blok2_figur',
    'exclude_1c', 'figura', 'html_blok', 'html_blokin', 'html_page', 'html_tabcell',
    'LessObj', 'LessType', 'LessWrk', 'lvl', 'muscul', 'practik', 'prepod',
    'progress', 'purpose', 'schedule', 'shownum', 'smssendlog', 'sys_tab', 'trenertype',
];

$csvDir = $argv[1] ?? null;
if ($csvDir === null || !is_dir($csvDir)) {
    fwrite(STDERR, "Usage: php import-csv.php <csv-directory>\n");
    exit(1);
}

$config = new Config(__DIR__ . '/../config.ini');
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config->get('db', 'host', '127.0.0.1'),
        (int) $config->get('db', 'port', '3306'),
        $config->get('db', 'name')
    ),
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('SET UNIQUE_CHECKS = 0');

$grandTotal = 0;
$startedAt = microtime(true);

foreach ($tables as $table) {
    $file = $csvDir . DIRECTORY_SEPARATOR . $table . '.csv';
    if (!is_file($file)) {
        echo "SKIP  {$table} (no {$table}.csv found)\n";
        continue;
    }

    try {
        $count = importTable($pdo, $table, $file);
        $grandTotal += $count;
        echo "OK    {$table}: {$count} rows\n";
    } catch (\Throwable $e) {
        echo "ERROR {$table}: " . $e->getMessage() . "\n";
    }
}

$pdo->exec('SET UNIQUE_CHECKS = 1');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$elapsed = round(microtime(true) - $startedAt, 1);
echo "\nDone: {$grandTotal} rows total in {$elapsed}s.\n";

function importTable(PDO $pdo, string $table, string $file): int
{
    $handle = fopen($file, 'r');
    if ($handle === false) {
        throw new \RuntimeException("could not open {$file}");
    }

    // PHP's fgetcsv treats backslash as an escape character by default,
    // which is not part of RFC 4180 (only doubled quotes are) and the
    // exporter doesn't use it either. Real data was found containing text
    // ending in a literal backslash right before the closing quote (an
    // ASCII-art emoticon in a lesson comment), which fgetcsv's default
    // then misreads as an escaped quote, corrupting the field boundary.
    // Passing '' as the escape character (PHP 7.4+) disables that
    // proprietary behaviour and parses strictly as RFC 4180.
    $columns = fgetcsv($handle, 0, ',', '"', '');
    if ($columns === false || $columns === null) {
        fclose($handle);
        throw new \RuntimeException('empty file (no header row)');
    }

    $quotedColumns = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $statement = $pdo->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");

    $pdo->beginTransaction();
    $count = 0;
    try {
        $lineNumber = 1; // header was line 1
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $lineNumber++;
            if (count($row) === 1 && $row[0] === null) {
                continue; // trailing blank line
            }

            if (count($row) !== count($columns)) {
                throw new \RuntimeException(sprintf(
                    'line %d has %d field(s), expected %d (columns: %s)',
                    $lineNumber,
                    count($row),
                    count($columns),
                    implode(', ', $columns)
                ));
            }

            // The exporter writes NULL as a bare, unquoted "\N" token
            // (distinct from a quoted empty string, which fgetcsv also
            // reports as ''); everything else passes through as the exact
            // text that was exported (dates already in MySQL's
            // "Y-m-d H:i:s" shape, booleans already "0"/"1").
            $values = array_map(static fn($value) => $value === '\N' ? null : $value, $row);

            $statement->execute($values);
            $count++;

            if ($count % 500 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        fclose($handle);
    }

    return $count;
}
