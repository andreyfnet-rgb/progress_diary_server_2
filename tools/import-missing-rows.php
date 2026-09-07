<?php

declare(strict_types=1);

/**
 * One-off catch-up importer: inserts only the CSV rows whose primary key
 * isn't already present in the target MySQL table, skipping everything
 * else. Built for the gap between the original one-time migration and the
 * dp.galladance.com cutover -- during that window the OLD server (still
 * backed by Access) kept accepting real writes, so a handful of new
 * DP_blok1_less/DP_blok2_figur rows exist in Access but not yet in MySQL.
 * Confirmed via tools/find-missing-ids.php first (exact ID-set diff, not
 * just a row-count comparison -- Access AutoNumber has gaps from years of
 * deletions, so counts alone aren't reliable).
 *
 * Deliberately NOT used for `schedule`: that table is now owned going
 * forward by ScheduleDbfImportService/Schedule1cSyncService, whose MySQL
 * row IDs no longer correspond to Access's own numbering, and which may
 * hold newer shdl_datecc confirmations than this stale Access snapshot.
 *
 * Usage: php import-missing-rows.php <csv-path> <id-column> <table>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Infrastructure\Config;

[, $csvPath, $idColumn, $table] = $argv;

$config = new Config(dirname(__DIR__) . '/config.ini');
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

$existing = [];
foreach ($pdo->query("SELECT `{$idColumn}` FROM `{$table}`") as $row) {
    $existing[(string) $row[$idColumn]] = true;
}

$handle = fopen($csvPath, 'r');
$columns = fgetcsv($handle, 0, ',', '"', '');
$idIndex = array_search($idColumn, $columns, true);
if ($idIndex === false) {
    exit("Column {$idColumn} not found in CSV header\n");
}

$quotedColumns = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
$placeholders = implode(', ', array_fill(0, count($columns), '?'));
$statement = $pdo->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");

$inserted = 0;
$skipped = 0;
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    if (count($row) === 1 && $row[0] === null) {
        continue;
    }
    if (isset($existing[$row[$idIndex]])) {
        $skipped++;
        continue;
    }

    $values = array_map(static fn ($value) => $value === '\N' ? null : $value, $row);
    $statement->execute($values);
    $inserted++;
}
fclose($handle);

echo "{$table}: inserted {$inserted}, skipped (already present) {$skipped}\n";
