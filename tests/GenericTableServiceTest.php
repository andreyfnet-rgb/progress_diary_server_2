<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Data\SchemaGuard;
use Gdpd\Domain\GenericTableService;
use Gdpd\Infrastructure\Logger;

/**
 * Integration test against a real local MySQL instance (see
 * migrations/schema.sql applied to the "gdpd_dev" database as part of
 * local setup). Skips itself if that database isn't reachable, so the
 * pure-function tests still run in environments without MySQL configured.
 */
return function (TestRunner $t): void {
    $t->group('GenericTableService (MySQL integration)');

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
    $service = new GenericTableService($db, $schema, $log);

    // --- fixtures -------------------------------------------------
    $db->execute('DELETE FROM prepod WHERE prp_id IN (900001, 900002)');
    $db->execute('DELETE FROM Client WHERE cln_id = 900001');
    // put_dattab's update path looks up the table's key column via
    // sys_tab.tab_idkey, exactly like the legacy server -- needs a real
    // entry to exercise that path in this test.
    $db->execute('DELETE FROM sys_tab WHERE tab_name = ?', ['prepod']);
    $db->execute('INSERT INTO sys_tab (id, tab_name, tab_idkey) VALUES (900001, ?, ?)', ['prepod', 'prp_id']);
    $db->execute(
        'INSERT INTO prepod (prp_id, prp_name, prp_phone, prp_out, prp_pass) VALUES (?, ?, ?, ?, ?)',
        [900001, 'Test Teacher', '79265990131', 0, '12345']
    );
    $db->execute(
        'INSERT INTO Client (cln_id, cln_name, cln_phone) VALUES (?, ?, ?)',
        [900001, 'Test Client', '79000000000']
    );

    $t->test('getdattab returns rows with False for a boolean column', function (TestRunner $t) use ($service): void {
        $json = $service->getDatTab('prepod', ['prp_id' => '900001']);
        $rows = json_decode($json, true);
        $t->assertTrue(is_array($rows) && count($rows) === 1, "expected one row, got: {$json}");
        $t->assertSame('Test Teacher', $rows[0]['prp_name']);
        $t->assertSame('False', $rows[0]['prp_out']);
    });

    $t->test('getdattab table name is case-insensitive (Prepod == prepod)', function (TestRunner $t) use ($service): void {
        $json = $service->getDatTab('Prepod', ['prp_id' => '900001']);
        $rows = json_decode($json, true);
        $t->assertSame('Test Teacher', $rows[0]['prp_name']);
    });

    $t->test('getdattab empty-string value filters on IS NULL', function (TestRunner $t) use ($db, $service): void {
        $db->execute('UPDATE prepod SET prp_link = NULL WHERE prp_id = 900001');
        $json = $service->getDatTab('prepod', ['prp_id' => '900001', 'prp_link' => '']);
        $rows = json_decode($json, true);
        $t->assertTrue(count($rows) === 1, "expected the row with NULL prp_link to match, got: {$json}");
    });

    $t->test('getdattab "where" filter is passed through raw', function (TestRunner $t) use ($service): void {
        $json = $service->getDatTab('prepod', ['where' => "prp_phone='79265990131'"]);
        $rows = json_decode($json, true);
        $t->assertSame('Test Teacher', $rows[0]['prp_name']);
    });

    $t->test('getdattab rejects an unknown table', function (TestRunner $t) use ($service): void {
        $result = $service->getDatTab('no_such_table', null);
        $t->assertTrue(str_contains($result, 'Error:'), $result);
    });

    $t->test('getdattab rejects an unknown column in the filter', function (TestRunner $t) use ($service): void {
        $result = $service->getDatTab('prepod', ['no_such_column' => '1']);
        $t->assertTrue(str_contains($result, 'Error:'), $result);
    });

    $t->test('putdattab inserts a new row and returns "ok"', function (TestRunner $t) use ($db, $service): void {
        $result = $service->putDatTab('prepod', [
            ['prp_id' => 900002, 'prp_name' => 'Second Teacher', 'prp_phone' => '79111111111', 'prp_out' => 0],
        ]);
        $t->assertSame('ok', $result);

        $rows = $db->query('SELECT prp_name FROM prepod WHERE prp_id = 900002');
        $t->assertSame('Second Teacher', $rows[0]['prp_name'] ?? null);
    });

    $t->test('putdattab updates an existing row by id and returns "ok"', function (TestRunner $t) use ($db, $service): void {
        $result = $service->putDatTab('prepod', [
            ['id' => 900002, 'prp_name' => 'Renamed Teacher'],
        ]);
        $t->assertSame('ok', $result);

        $rows = $db->query('SELECT prp_name FROM prepod WHERE prp_id = 900002');
        $t->assertSame('Renamed Teacher', $rows[0]['prp_name'] ?? null);
    });

    $t->test('putdattab returns "ok" even when a row in the batch fails (legacy contract)', function (TestRunner $t) use ($service): void {
        $result = $service->putDatTab('prepod', [
            ['prp_id' => 900002, 'prp_name' => 'Duplicate primary key, should fail'], // prp_id 900002 already exists
        ]);
        $t->assertSame('ok', $result);
    });

    // --- cleanup ----------------------------------------------------
    $db->execute('DELETE FROM prepod WHERE prp_id IN (900001, 900002)');
    $db->execute('DELETE FROM Client WHERE cln_id = 900001');
    $db->execute('DELETE FROM sys_tab WHERE tab_name = ?', ['prepod']);
};
