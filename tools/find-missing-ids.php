<?php

declare(strict_types=1);

/**
 * One-off diagnostic: compares a fresh CSV export's primary-key column
 * against what's already in MySQL, to find exactly which rows are new
 * since the original migration (Access AutoNumber has gaps from years of
 * deletions, so comparing row counts or "id > previous max" isn't
 * reliable -- this does a real set difference).
 *
 * Usage: php find-missing-ids.php <csv-path> <id-column> <mysql-table> <mysql-id-column>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI-only.\n");
}

ini_set('memory_limit', '512M'); // one-off diagnostic against large tables

require __DIR__ . '/../src/autoload.php';

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Config;

[, $csvPath, $idColumn, $table, $mysqlIdColumn] = $argv;

$config = new Config(dirname(__DIR__) . '/config.ini');
$db = new Db(
    $config->get('db', 'host', '127.0.0.1'),
    (int) $config->get('db', 'port', '3306'),
    $config->get('db', 'name'),
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', '')
);

$existing = [];
foreach ($db->query("SELECT `{$mysqlIdColumn}` FROM `{$table}`") as $row) {
    $existing[(string) $row[$mysqlIdColumn]] = true;
}
echo "MySQL {$table}: " . count($existing) . " existing rows\n";

$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle, 0, ',', '"', '');
$idIndex = array_search($idColumn, $header, true);
if ($idIndex === false) {
    exit("Column {$idColumn} not found in CSV header\n");
}

$missing = [];
$total = 0;
while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $total++;
    $id = $fields[$idIndex];
    if (!isset($existing[$id])) {
        $missing[] = $id;
    }
}
fclose($handle);

echo "CSV total rows: {$total}\n";
echo "Missing from MySQL: " . count($missing) . "\n";
sort($missing, SORT_NUMERIC);
echo "Missing IDs: " . implode(',', array_slice($missing, 0, 50)) . (count($missing) > 50 ? '...' : '') . "\n";

$extra = array_diff(array_keys($existing), array_map('strval', $missing));
// Also report the reverse: rows in MySQL not in this CSV at all (would be
// unexpected -- e.g. rows added directly via the new API since cutover
// testing began, or a real discrepancy worth looking at).
$csvIds = [];
$handle = fopen($csvPath, 'r');
fgetcsv($handle, 0, ',', '"', '');
while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $csvIds[$fields[$idIndex]] = true;
}
fclose($handle);
$onlyInMysql = array_diff(array_keys($existing), array_keys($csvIds));
echo "In MySQL but not in this CSV: " . count($onlyInMysql) . "\n";
if (count($onlyInMysql) > 0 && count($onlyInMysql) <= 50) {
    echo "IDs: " . implode(',', $onlyInMysql) . "\n";
}
