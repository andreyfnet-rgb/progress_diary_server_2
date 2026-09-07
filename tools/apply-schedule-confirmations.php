<?php

declare(strict_types=1);

/**
 * Applies the {shdl_id, shdl_datecc, shdl_dtlesend, shdl_relocat} rows
 * produced by find-missing-confirmations.php. Re-checks shdl_datecc is
 * still NULL right before writing (defends against a race with real
 * traffic that may have confirmed the same lesson through the new server
 * in the meantime) and only ever fills in a currently-empty mark -- never
 * overwrites one that's already set.
 *
 * Usage: php apply-schedule-confirmations.php <schedule_confirmations_to_apply.json>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Infrastructure\Config;

$jsonPath = $argv[1];
$rows = json_decode(file_get_contents($jsonPath), true);

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

$statement = $pdo->prepare(
    'UPDATE schedule SET shdl_datecc = ?, shdl_dtlesend = ?, shdl_relocat = ?
     WHERE shdl_id = ? AND shdl_datecc IS NULL'
);

$applied = 0;
$skippedRace = 0;
foreach ($rows as $row) {
    $affected = $statement->execute([
        $row['shdl_datecc'],
        $row['shdl_dtlesend'],
        $row['shdl_relocat'],
        $row['shdl_id'],
    ]) ? $statement->rowCount() : 0;

    if ($affected > 0) {
        $applied++;
    } else {
        $skippedRace++;
    }
}

echo "Applied: {$applied}, already confirmed by something else since the scan: {$skippedRace}\n";
