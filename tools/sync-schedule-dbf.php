<?php

declare(strict_types=1);

/**
 * Ports the "run addschedule_dbf on a timer" half of the legacy app's
 * background jobs (GDPDAP_run.pas's time_scheduleTimer, gated by
 * [Schedule] work=1). Meant to run periodically via cron, e.g. every few
 * minutes -- ScheduleDbfImportService itself skips a club's file if its
 * mtime hasn't changed since the last run, so frequent invocation is
 * cheap.
 *
 * Usage: php sync-schedule-dbf.php [/path/to/import_dbf]
 *
 * The directory argument is optional -- defaults to <project root>/import_dbf.
 * This is required for real deployment: the hosting's cron UI (Timeweb, at
 * least) only lets you pick a PHP file to run, with no way to pass
 * command-line arguments, so a cron job invoking this with no argument at
 * all must still work.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Data\Db;
use Gdpd\Domain\ScheduleDbfImportService;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;

$projectRoot = dirname(__DIR__);
$importDir = $argv[1] ?? ($projectRoot . '/import_dbf');
if (!is_dir($importDir)) {
    fwrite(STDERR, "Usage: php sync-schedule-dbf.php [import_dbf-directory] (default: {$projectRoot}/import_dbf, which doesn't exist)\n");
    exit(1);
}

$config = new Config($projectRoot . '/config.ini');
$log = new Logger($projectRoot);

$db = new Db(
    $config->get('db', 'host', '127.0.0.1'),
    (int) $config->get('db', 'port', '3306'),
    $config->get('db', 'name'),
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', '')
);

$service = new ScheduleDbfImportService($db, $log);
$service->importAll($importDir);

// The ported service only logs errors (matching the original Delphi
// job, which never logged a success line either) -- this line exists
// purely so a cron entry is distinguishable from "cron never ran" when
// every club's file happens to be unchanged since the last run.
$log->log('shed_dbf', 'Проверка завершена в ' . date('d.m.Y H:i:s'));

echo "Done.\n";
