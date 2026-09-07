<?php

declare(strict_types=1);

/**
 * Companion to import-missing-rows.php: writes a small CSV containing only
 * the rows whose id ISN'T already in the local MySQL mirror, so that a
 * multi-hundred-MB full export doesn't need to be re-uploaded to the
 * hosting just to catch up a handful of new rows.
 *
 * Usage: php extract-missing-rows.php <source-csv> <id-column> <table> <out-csv>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI-only.\n");
}

ini_set('memory_limit', '512M');

require __DIR__ . '/../src/autoload.php';

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Config;

[, $sourceCsv, $idColumn, $table, $outCsv] = $argv;

$config = new Config(dirname(__DIR__) . '/config.ini');
$db = new Db(
    $config->get('db', 'host', '127.0.0.1'),
    (int) $config->get('db', 'port', '3306'),
    $config->get('db', 'name'),
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', '')
);

$existing = [];
foreach ($db->query("SELECT `{$idColumn}` FROM `{$table}`") as $row) {
    $existing[(string) $row[$idColumn]] = true;
}

$in = fopen($sourceCsv, 'r');
$header = fgetcsv($in, 0, ',', '"', '');
$idIndex = array_search($idColumn, $header, true);

$out = fopen($outCsv, 'w');
fputcsv($out, $header, ',', '"', '\\');

$written = 0;
while (($row = fgetcsv($in, 0, ',', '"', '')) !== false) {
    if (count($row) === 1 && $row[0] === null) {
        continue;
    }
    if (!isset($existing[$row[$idIndex]])) {
        // Re-quote every field the same way the original exporter did
        // (fputcsv's own quoting is close enough for round-tripping
        // through the same import-missing-rows.php reader).
        fputcsv($out, $row, ',', '"', '\\');
        $written++;
    }
}
fclose($in);
fclose($out);

echo "Wrote {$written} rows to {$outCsv}\n";
