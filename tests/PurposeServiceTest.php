<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Data\SchemaGuard;
use Gdpd\Domain\PurposeService;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('PurposeService (MySQL integration)');

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
    $service = new PurposeService($db, $schema, $log);

    $db->execute('DELETE FROM prepod WHERE prp_id = 900030');
    $db->execute('DELETE FROM Client WHERE cln_id = 900030');
    $db->execute('DELETE FROM purpose WHERE clpp_idcln = 900030');
    $db->execute('INSERT INTO prepod (prp_id, prp_name) VALUES (900030, ?)', ['Purpose Test Teacher']);
    $db->execute('INSERT INTO Client (cln_id, cln_name, cln_phone) VALUES (900030, ?, ?)', ['Purpose Test Client', '79000000040']);

    $newId = null;

    $t->test('putPurpose with id_rec=0 inserts a new row', function (TestRunner $t) use ($service, $db, &$newId): void {
        $result = $service->putPurpose([
            'id_rec' => '0',
            'clpp_idcln' => 900030,
            'clpp_idprp_on' => 900030,
            'clpp_dateon' => '07.09.2026',
            'clpp_dateplan' => '30.09.2026',
            'clpp_purpose' => 'Learn the basic step',
        ]);
        $t->assertSame('ok', $result);

        $rows = $db->query('SELECT clpp_id, clpp_purpose FROM purpose WHERE clpp_idcln = 900030');
        $t->assertSame(1, count($rows));
        $t->assertSame('Learn the basic step', $rows[0]['clpp_purpose']);
        $newId = (int) $rows[0]['clpp_id'];
    });

    $t->test('getPurpose by id_cln returns it via the purpose_all view', function (TestRunner $t) use ($service): void {
        $json = $service->getPurpose(['id_cln' => '900030']);
        $rows = json_decode($json, true);
        $t->assertSame(1, count($rows));
        $t->assertSame('Learn the basic step', $rows[0]['clpp_purpose']);
        $t->assertSame('Purpose Test Client', $rows[0]['cln_name']);
    });

    $t->test('getPurpose by cln_phone works too', function (TestRunner $t) use ($service): void {
        $json = $service->getPurpose(['cln_phone' => '79000000040']);
        $rows = json_decode($json, true);
        $t->assertSame(1, count($rows));
    });

    $t->test('putPurpose with a real id_rec updates only the provided fields', function (TestRunner $t) use ($service, $db, &$newId): void {
        $result = $service->putPurpose([
            'id_rec' => (string) $newId,
            'clpp_dateoff' => '10.09.2026',
            'clpp_info' => 'done early',
        ]);
        $t->assertSame('ok', $result);

        $row = $db->query('SELECT clpp_purpose, clpp_info FROM purpose WHERE clpp_id = ?', [$newId])[0];
        // clpp_purpose was NOT in this update's body, so it must survive untouched.
        $t->assertSame('Learn the basic step', $row['clpp_purpose']);
        $t->assertSame('done early', $row['clpp_info']);
    });

    $t->test('putPurpose with an empty body returns "JSON has no text" (no ERROR: prefix)', function (TestRunner $t) use ($service): void {
        $t->assertSame('JSON has no text', $service->putPurpose([]));
    });

    $db->execute('DELETE FROM purpose WHERE clpp_idcln = 900030');
    $db->execute('DELETE FROM prepod WHERE prp_id = 900030');
    $db->execute('DELETE FROM Client WHERE cln_id = 900030');
};
