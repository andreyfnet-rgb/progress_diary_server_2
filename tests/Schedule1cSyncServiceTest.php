<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Domain\Schedule1cSyncService;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('Schedule1cSyncService (MySQL integration)');

    try {
        $db = new Db('127.0.0.1', 3306, 'gdpd_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $config = new Config('/nonexistent-config-not-needed-for-processItems.ini');
    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $service = new Schedule1cSyncService($db, $config, $log);

    $db->execute('DELETE FROM schedule WHERE shdl_idclb = 900070');
    $db->execute('DELETE FROM clubs WHERE clb_id = 900070');
    $db->execute("DELETE FROM prepod WHERE prp_phone = '79001112277'");
    $db->execute("DELETE FROM Client WHERE cln_phone = '79007778877'");
    $db->execute("DELETE FROM exclude_1c WHERE room_code = 'ROOM_EXCLUDED_900070' OR class_code = 'CLASS_EXCLUDED_900070'");

    $db->execute(
        'INSERT INTO clubs (clb_id, clb_name, clb_id1c, clb_in1с) VALUES (?, ?, ?, 1)',
        [900070, '1C Test Club', 'DEP900070']
    );
    $db->execute(
        'INSERT INTO exclude_1c (room_code) VALUES (?)',
        ['ROOM_EXCLUDED_900070']
    );

    $baseItem = static fn (array $overrides = []): array => array_replace([
        'Instructor' => ['Phone' => '79001112277', 'Name' => 'Test 1C Teacher'],
        'Client' => ['Phone' => '79007778877', 'Name' => 'Test 1C Client'],
        'Class' => [
            'Code' => 'CLASS_OK',
            'Name' => 'Individual 1C lesson',
            'DateTime_Start' => '2026-09-10T18:00:00.000',
            'DateTime_End' => '2026-09-10T18:45:00.000',
            'Status' => 'Подтверждено',
        ],
        'Department' => ['Id' => 'DEP900070'],
        'Room' => ['Code' => 'ROOM_OK', 'Name' => 'Main Hall'],
    ], $overrides);

    $t->test('processItems creates prepod/Client/schedule rows for a valid item', function (TestRunner $t) use ($service, $baseItem, $db): void {
        $service->processItems([$baseItem()]);

        $teacher = $db->query("SELECT prp_name FROM prepod WHERE prp_phone = '79001112277'");
        $t->assertSame(1, count($teacher));
        $t->assertSame('Test 1C Teacher', $teacher[0]['prp_name']);

        $client = $db->query("SELECT cln_name FROM Client WHERE cln_phone = '79007778877'");
        $t->assertSame(1, count($client));

        $rows = $db->query('SELECT * FROM schedule WHERE shdl_idclb = 900070');
        $t->assertSame(1, count($rows));
        $t->assertSame('2026-09-10 18:00:00', (string) $rows[0]['shdl_dtleson']);
        $t->assertSame('2026-09-10 18:45:00', (string) $rows[0]['shdl_dtlesoff']);
        $t->assertSame(0, (int) $rows[0]['shdl_del']);
        $t->assertSame(0, (int) $rows[0]['shdl_dtlesend'], 'new rows must start at 0 (not NULL), matching dp.galladance.com pd.php\'s own shdl_dtlesend=0 filter for pending lessons');
    });

    $t->test('re-processing the same item updates rather than duplicates, and detects cancellation', function (TestRunner $t) use ($service, $baseItem, $db): void {
        $service->processItems([$baseItem(['Class' => array_replace($baseItem()['Class'], ['Status' => 'Отменено'])])]);

        $rows = $db->query('SELECT * FROM schedule WHERE shdl_idclb = 900070');
        $t->assertSame(1, count($rows), 'must update the existing row, not insert a second one');
        $t->assertSame(1, (int) $rows[0]['shdl_del']);
    });

    $t->test('an item for an unknown department is skipped (club is never auto-created)', function (TestRunner $t) use ($service, $baseItem, $db): void {
        $before = $db->query('SELECT COUNT(*) AS n FROM clubs')[0]['n'];
        $service->processItems([$baseItem(['Department' => ['Id' => 'NO_SUCH_DEPARTMENT_900070']])]);
        $after = $db->query('SELECT COUNT(*) AS n FROM clubs')[0]['n'];
        $t->assertSame($before, $after);
    });

    $t->test('an item whose room_code is in exclude_1c is skipped entirely', function (TestRunner $t) use ($service, $baseItem, $db): void {
        $before = $db->query('SELECT COUNT(*) AS n FROM schedule WHERE shdl_idclb = 900070')[0]['n'];
        $service->processItems([$baseItem(['Room' => ['Code' => 'ROOM_EXCLUDED_900070', 'Name' => 'Excluded Room']])]);
        $after = $db->query('SELECT COUNT(*) AS n FROM schedule WHERE shdl_idclb = 900070')[0]['n'];
        $t->assertSame($before, $after);
    });

    $t->test('a malformed item is logged and does not stop the rest of the batch', function (TestRunner $t) use ($service, $baseItem, $db): void {
        $db->execute('DELETE FROM schedule WHERE shdl_idclb = 900070');

        $service->processItems([
            ['Instructor' => null], // missing everything -- must not throw out of processItems
            $baseItem(),
        ]);

        $rows = $db->query('SELECT * FROM schedule WHERE shdl_idclb = 900070');
        $t->assertSame(1, count($rows), 'the second, valid item must still be processed');
    });

    // --- cleanup ---------------------------------------------------------
    $db->execute('DELETE FROM schedule WHERE shdl_idclb = 900070');
    $db->execute('DELETE FROM clubs WHERE clb_id = 900070');
    $db->execute("DELETE FROM prepod WHERE prp_phone = '79001112277'");
    $db->execute("DELETE FROM Client WHERE cln_phone = '79007778877'");
    $db->execute("DELETE FROM exclude_1c WHERE room_code = 'ROOM_EXCLUDED_900070'");
};
