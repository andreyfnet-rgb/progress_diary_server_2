<?php

declare(strict_types=1);

/**
 * One-off diagnostic: `schedule`'s own row IDs no longer match between the
 * Access snapshot and MySQL (ScheduleDbfImportService/Schedule1cSyncService
 * insert new MySQL-assigned IDs going forward), so a completion mark
 * (shdl_datecc/shdl_dtlesend) that a teacher set via the OLD server can
 * only be matched back to its MySQL row by natural key: client, teacher,
 * club, lesson name, and exact start/end time.
 *
 * Prints, for every Access row that has a confirmation MySQL doesn't have
 * yet, the natural key and the confirmation values -- read-only, makes no
 * changes.
 *
 * Usage: php find-missing-confirmations.php <schedule.csv>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI-only.\n");
}

ini_set('memory_limit', '768M');

require __DIR__ . '/../src/autoload.php';

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Config;

$csvPath = $argv[1];

$config = new Config(dirname(__DIR__) . '/config.ini');
$db = new Db(
    $config->get('db', 'host', '127.0.0.1'),
    (int) $config->get('db', 'port', '3306'),
    $config->get('db', 'name'),
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', '')
);

function naturalKey(string $cln, string $prp, string $clb, string $nameless, string $on, string $off): string
{
    return $cln . '|' . $prp . '|' . $clb . '|' . $nameless . '|' . $on . '|' . $off;
}

// Index MySQL by natural key -> [shdl_id, has_confirmation, duplicate_flag]
$mysqlIndex = [];
$mysqlDuplicateKeys = [];
foreach ($db->query('SELECT shdl_id, shdl_idcln, shdl_idprp, shdl_idclb, shdl_nameless, shdl_dtleson, shdl_dtlesoff, shdl_datecc FROM schedule') as $row) {
    $key = naturalKey((string) $row['shdl_idcln'], (string) $row['shdl_idprp'], (string) $row['shdl_idclb'], (string) $row['shdl_nameless'], (string) $row['shdl_dtleson'], (string) $row['shdl_dtlesoff']);
    if (isset($mysqlIndex[$key])) {
        $mysqlDuplicateKeys[$key] = true;
    }
    $mysqlIndex[$key] = ['shdl_id' => $row['shdl_id'], 'has_confirmation' => $row['shdl_datecc'] !== null];
}
echo 'MySQL schedule rows indexed: ' . count($mysqlIndex) . ' (' . count($mysqlDuplicateKeys) . " ambiguous duplicate keys)\n";

$in = fopen($csvPath, 'r');
$header = fgetcsv($in, 0, ',', '"', '');
$idx = array_flip($header);

$candidates = [];
$csvKeyCounts = [];
while (($row = fgetcsv($in, 0, ',', '"', '')) !== false) {
    if (count($row) === 1 && $row[0] === null) {
        continue;
    }
    $datecc = $row[$idx['shdl_datecc']];
    if ($datecc === '\N' || $datecc === '') {
        continue;
    }
    $key = naturalKey($row[$idx['shdl_idcln']], $row[$idx['shdl_idprp']], $row[$idx['shdl_idclb']], $row[$idx['shdl_nameless']], $row[$idx['shdl_dtleson']], $row[$idx['shdl_dtlesoff']]);
    $csvKeyCounts[$key] = ($csvKeyCounts[$key] ?? 0) + 1;
    $candidates[$key] = [
        'shdl_datecc' => $datecc,
        'shdl_dtlesend' => $row[$idx['shdl_dtlesend']],
        'shdl_relocat' => $row[$idx['shdl_relocat']] ?? null,
    ];
}
fclose($in);

$toUpdate = [];
$ambiguousInCsv = 0;
$noMysqlMatch = 0;
$alreadyConfirmed = 0;
foreach ($candidates as $key => $data) {
    if (($csvKeyCounts[$key] ?? 0) > 1) {
        $ambiguousInCsv++;
        continue;
    }
    if (!isset($mysqlIndex[$key])) {
        $noMysqlMatch++;
        if ($noMysqlMatch <= 5) {
            fwrite(STDERR, "NO MATCH: {$key}\n");
        }
        continue;
    }
    if (isset($mysqlDuplicateKeys[$key])) {
        continue; // ambiguous on the MySQL side too
    }
    if ($mysqlIndex[$key]['has_confirmation']) {
        $alreadyConfirmed++;
        continue;
    }
    $toUpdate[] = ['shdl_id' => $mysqlIndex[$key]['shdl_id']] + $data;
}

echo 'Candidates in CSV with a confirmation: ' . count($candidates) . "\n";
echo "Ambiguous (same key appears >1x in CSV): {$ambiguousInCsv}\n";
echo "No matching MySQL row at all: {$noMysqlMatch}\n";
echo "Already confirmed in MySQL: {$alreadyConfirmed}\n";
echo 'Safe to update: ' . count($toUpdate) . "\n";

file_put_contents(dirname($csvPath) . '/schedule_confirmations_to_apply.json', json_encode($toUpdate, JSON_PRETTY_PRINT));
echo 'Wrote ' . dirname($csvPath) . "/schedule_confirmations_to_apply.json\n";
