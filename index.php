<?php

declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use Gdpd\Api\Dispatcher;
use Gdpd\Api\Routes;
use Gdpd\Data\Db;
use Gdpd\Data\SchemaGuard;
use Gdpd\Domain\AuthService;
use Gdpd\Domain\CalendService;
use Gdpd\Domain\GenericTableService;
use Gdpd\Domain\InRecPdService;
use Gdpd\Domain\Lookups\DanceService;
use Gdpd\Domain\Lookups\LookupService;
use Gdpd\Domain\LvlStatService;
use Gdpd\Domain\ProcEndService;
use Gdpd\Domain\PurposeService;
use Gdpd\Domain\SalaryService;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\SmsGateway;

$projectRoot = __DIR__;
$config = new Config($projectRoot . '/config.ini');
$log = new Logger($projectRoot);

$dbName = $config->get('db', 'name');
$db = new Db(
    $config->get('db', 'host', '127.0.0.1'),
    (int) $config->get('db', 'port', '3306'),
    $dbName,
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', '')
);
$schema = new SchemaGuard($db, $dbName);
$genericTableService = new GenericTableService($db, $schema, $log);
$lookupService = new LookupService($db, $schema, $log);
$danceService = new DanceService($db, $log);
$smsGateway = new SmsGateway($config, $log);
$authService = new AuthService($db, $smsGateway, $config, $log);
$purposeService = new PurposeService($db, $schema, $log);
$calendService = new CalendService($db, $log);
$procEndService = new ProcEndService($db, $log);

$salaryDb = new Db(
    $config->get('salary_db', 'host', '127.0.0.1'),
    (int) $config->get('salary_db', 'port', '3306'),
    $config->get('salary_db', 'name'),
    $config->get('salary_db', 'user', 'root'),
    $config->get('salary_db', 'pass', '')
);
$salaryService = new SalaryService($salaryDb, $log);
$inRecPdService = new InRecPdService($db, $schema, $log);
$lvlStatService = new LvlStatService($db, $log);

$dispatcher = new Dispatcher($config, $log);
Routes::register(
    $dispatcher,
    $genericTableService,
    $lookupService,
    $danceService,
    $authService,
    $purposeService,
    $calendService,
    $procEndService,
    $salaryService,
    $inRecPdService,
    $lvlStatService,
);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$routeName = trim($path, '/');
$routeKey = strtolower($routeName);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$restrictions = Routes::methodRestrictions();

if ($routeName === '') {
    serveDefaultPage($config, $projectRoot);
} elseif ($routeKey === 'admindp') {
    // Mini-CMS page (admin.pas's showpg('show_shed')) -- not ported yet.
    echo 'TODO: admin_dp (mini-CMS) not implemented yet';
} elseif (array_key_exists($routeKey, $restrictions)) {
    $restriction = $restrictions[$routeKey];
    $methodAllowed = $restriction === 'any'
        || ($restriction === 'get' && $method === 'GET')
        || ($restriction === 'put' && $method === 'PUT');

    if (!$methodAllowed) {
        // WebBroker wouldn't route this path/method combination to any
        // action at all in the original (the GET-only/PUT-only routes had
        // an explicit MethodType) -- closest available equivalent here.
        http_response_code(404);
    } else {
        $dispatcher->handle($routeName);
    }
} else {
    // Unknown path entirely: WbMdul.dfm's DefaultHandler has Default=True,
    // i.e. it's WebBroker's fallback for any path that doesn't match a
    // known action, not just an exact "/".
    serveDefaultPage($config, $projectRoot);
}

function serveDefaultPage(Config $config, string $projectRoot): void
{
    $fileName = $config->get('html', 'firstpage');
    $path = $projectRoot . '/' . ltrim($fileName, '/\\');
    if (is_file($path)) {
        readfile($path);
    }
}
