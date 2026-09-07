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
 * Usage: php sync-schedule-dbf.php /path/to/import_dbf
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

$importDir = $argv[1] ?? null;
if ($importDir === null || !is_dir($importDir)) {
    fwrite(STDERR, "Usage: php sync-schedule-dbf.php <import_dbf-directory>\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
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

echo "Done.\n";
