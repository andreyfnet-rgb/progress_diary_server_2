<?php

declare(strict_types=1);

/**
 * Ports the "run addschedule_1c on a timer" half of the legacy app's
 * background jobs (GDPDAP_run.pas's time_scheduleTimer, gated by
 * [Schedule] work=1). Meant to run periodically via cron, e.g. every few
 * minutes, alongside sync-schedule-dbf.php.
 *
 * Usage: php sync-schedule-1c.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Data\Db;
use Gdpd\Domain\Schedule1cSyncService;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;

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

$service = new Schedule1cSyncService($db, $config, $log);
$service->sync();

echo "Done.\n";
