<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Data\SchemaGuard;
use Gdpd\Domain\Lookups\DanceService;
use Gdpd\Domain\Lookups\LookupService;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('LookupService / DanceService (MySQL integration)');

    try {
        $db = new Db('127.0.0.1', 3306, 'gdpd_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $schema = new SchemaGuard($db, 'gdpd_dev');
    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $lookup = new LookupService($db, $schema, $log);
    $dance = new DanceService($db, $log);

    // --- fixtures ---------------------------------------------------
    $db->execute('DELETE FROM prepod WHERE prp_id IN (900010, 900011)');
    $db->execute('DELETE FROM Client WHERE cln_id = 900010');
    $db->execute('DELETE FROM dancetype WHERE dt_id = 900001');
    $db->execute('DELETE FROM dance WHERE dns_id IN (900001, 900002)');
    $db->execute('INSERT INTO prepod (prp_id, prp_name, prp_phone, prp_out) VALUES (900010, ?, ?, 0), (900011, ?, ?, 1)', [
        'Active Teacher', '79000000010', 'Retired Teacher', '79000000011',
    ]);
    $db->execute('INSERT INTO Client (cln_id, cln_name, cln_phone) VALUES (900010, ?, ?)', ['Zzz Client', '79000000020']);
    $db->execute('INSERT INTO dancetype (dt_id, dt_name) VALUES (900001, ?)', ['Test Direction']);
    $db->execute('INSERT INTO dance (dns_id, dns_name, id_dt) VALUES (900001, ?, 900001), (900002, ?, 900001)', ['Test Dance A', 'Test Dance B']);

    $t->test('getPrepod with no filter excludes prp_out=1 rows', function (TestRunner $t) use ($lookup): void {
        $rows = json_decode($lookup->getPrepod(null), true);
        $names = array_column($rows, 'prp_name');
        $t->assertTrue(in_array('Active Teacher', $names, true), 'expected active teacher present');
        $t->assertTrue(!in_array('Retired Teacher', $names, true), 'expected retired teacher excluded');
    });

    $t->test('getPrepod by id ignores prp_out filter (matches legacy SQL)', function (TestRunner $t) use ($lookup): void {
        $rows = json_decode($lookup->getPrepod(['id' => 900010]), true);
        $t->assertSame('Active Teacher', $rows[0]['prp_name'] ?? null);
    });

    $t->test('getClient by phone', function (TestRunner $t) use ($lookup): void {
        $rows = json_decode($lookup->getClient(['cln_phone' => '79000000020']), true);
        $t->assertSame('Zzz Client', $rows[0]['cln_name'] ?? null);
    });

    $t->test('getClient with unrecognized filter key is an error', function (TestRunner $t) use ($lookup): void {
        $result = $lookup->getClient(['nonsense' => '1']);
        $t->assertSame('ERROR: Invalid parameter', $result);
    });

    $t->test('dance with no filter groups by direction, dns_id is a JSON number', function (TestRunner $t) use ($dance): void {
        $json = $dance->getDance(null);
        $t->assertTrue(str_contains($json, '"dns_id":900001'), "expected unquoted dns_id, got: {$json}");
        $decoded = json_decode($json, true);
        $found = null;
        foreach ($decoded as $type) {
            if ($type['dt_id'] === '900001') {
                $found = $type;
            }
        }
        $t->assertTrue($found !== null, 'expected the test direction to be present');
        $t->assertSame(2, count($found['dance']));
    });

    $t->test('dance with an id filter returns a flat row with string dns_id', function (TestRunner $t) use ($dance): void {
        $json = $dance->getDance(['id' => 900001]);
        $t->assertTrue(str_contains($json, '"dns_id":"900001"'), "expected quoted dns_id, got: {$json}");
    });

    // --- cleanup ------------------------------------------------------
    $db->execute('DELETE FROM prepod WHERE prp_id IN (900010, 900011)');
    $db->execute('DELETE FROM Client WHERE cln_id = 900010');
    $db->execute('DELETE FROM dance WHERE dns_id IN (900001, 900002)');
    $db->execute('DELETE FROM dancetype WHERE dt_id = 900001');
};
