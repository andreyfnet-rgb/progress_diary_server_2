<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Domain\ScheduleDbfImportService;
use Gdpd\Infrastructure\Logger;

/**
 * Builds a synthetic dBase file matching the real club-export field layout
 * (confirmed against a real sample file, dp179.dbf) but with entirely
 * fake data -- no real client/teacher names or phone numbers.
 *
 * @param list<array<string, string|bool>> $rows
 */
function buildScheduleDbf(array $rows): string
{
    $fields = [
        ['name' => 'SECTIONNAM', 'type' => 'C', 'length' => 50],
        ['name' => 'SERVICENAM', 'type' => 'C', 'length' => 150],
        ['name' => 'RESOURCENA', 'type' => 'C', 'length' => 254],
        ['name' => 'RESOURCEID', 'type' => 'N', 'length' => 20],
        ['name' => 'RESOURCEPH', 'type' => 'C', 'length' => 254],
        ['name' => 'CLIENTNAME', 'type' => 'C', 'length' => 254],
        ['name' => 'CLIENTIDX', 'type' => 'N', 'length' => 20],
        ['name' => 'CLIENTPHON', 'type' => 'C', 'length' => 254],
        ['name' => 'CATEGORYNA', 'type' => 'C', 'length' => 20],
        ['name' => 'ISGROUP', 'type' => 'L', 'length' => 1],
        ['name' => 'DAYVALUE', 'type' => 'D', 'length' => 8],
        ['name' => 'TIMEINTERV', 'type' => 'C', 'length' => 254],
        ['name' => 'SEANSEID', 'type' => 'N', 'length' => 20],
        ['name' => 'STATUSNAME', 'type' => 'C', 'length' => 254],
    ];

    $recordLength = 1;
    foreach ($fields as $f) {
        $recordLength += $f['length'];
    }
    $headerLength = 32 + count($fields) * 32 + 1;

    $header = pack('C4', 0x03, 26, 9, 7) . pack('V', count($rows)) . pack('v', $headerLength) . pack('v', $recordLength) . str_repeat("\x00", 20);

    $descriptors = '';
    foreach ($fields as $f) {
        $descriptors .= str_pad($f['name'], 11, "\x00") . $f['type'] . str_repeat("\x00", 4) . chr($f['length']) . chr(0) . str_repeat("\x00", 14);
    }
    $descriptors .= "\x0D";

    $body = '';
    foreach ($rows as $row) {
        $body .= ' '; // not deleted
        foreach ($fields as $f) {
            $raw = $row[$f['name']] ?? '';
            $text = is_bool($raw) ? ($raw ? 'T' : 'F') : (string) $raw;
            $encoded = $f['type'] === 'C' ? (@iconv('UTF-8', 'CP866//IGNORE', $text) ?: $text) : $text;
            $body .= str_pad($encoded, $f['length']);
        }
    }

    return $header . $descriptors . $body;
}

return function (TestRunner $t): void {
    $t->group('ScheduleDbfImportService (MySQL integration)');

    try {
        $db = new Db('127.0.0.1', 3306, 'gdpd_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $service = new ScheduleDbfImportService($db, $log);

    $db->execute('DELETE FROM schedule WHERE shdl_idclb = 900060');
    $db->execute('DELETE FROM clubs WHERE clb_id = 900060');
    $db->execute("DELETE FROM prepod WHERE prp_phone IN ('79001112233','79004445566')");
    $db->execute("DELETE FROM Client WHERE cln_phone IN ('79007778899')");

    $importDir = sys_get_temp_dir() . '/gdpd_dbf_import_test_' . uniqid();
    mkdir($importDir);
    $dbfPath = $importDir . '/test900060.dbf';

    file_put_contents($dbfPath, buildScheduleDbf([
        // A normal, importable lesson.
        [
            'SECTIONNAM' => 'Group', 'SERVICENAM' => 'Individual lesson', 'RESOURCENA' => 'Test Teacher',
            'RESOURCEID' => '4242.00000', 'RESOURCEPH' => '9001112233 (м)',
            'CLIENTNAME' => 'Test Client', 'CLIENTIDX' => '9999.00000', 'CLIENTPHON' => '9007778899 (sms)',
            'CATEGORYNA' => 'Room 1', 'ISGROUP' => false, 'DAYVALUE' => '20260907',
            'TIMEINTERV' => '18:00 - 18:45', 'SEANSEID' => '1.00000', 'STATUSNAME' => 'Confirmed',
        ],
        // Excluded: SERVICENAM matches the "assist" filter.
        [
            'SECTIONNAM' => 'X', 'SERVICENAM' => 'Ассистирование', 'RESOURCENA' => 'Test Teacher 2',
            'RESOURCEID' => '5555.00000', 'RESOURCEPH' => '9004445566 (м)',
            'CLIENTNAME' => 'Someone', 'CLIENTIDX' => '1.00000', 'CLIENTPHON' => '9001112233 (sms)',
            'CATEGORYNA' => 'Room 2', 'ISGROUP' => false, 'DAYVALUE' => '20260907',
            'TIMEINTERV' => '10:00 - 10:45', 'SEANSEID' => '2.00000', 'STATUSNAME' => 'Confirmed',
        ],
        // Excluded: ISGROUP is true.
        [
            'SECTIONNAM' => 'X', 'SERVICENAM' => 'Group lesson', 'RESOURCENA' => 'Test Teacher',
            'RESOURCEID' => '4242.00000', 'RESOURCEPH' => '9001112233 (м)',
            'CLIENTNAME' => 'Someone', 'CLIENTIDX' => '1.00000', 'CLIENTPHON' => '9001112233 (sms)',
            'CATEGORYNA' => 'Room 2', 'ISGROUP' => true, 'DAYVALUE' => '20260907',
            'TIMEINTERV' => '11:00 - 11:45', 'SEANSEID' => '3.00000', 'STATUSNAME' => 'Confirmed',
        ],
        // Cancelled -- should be imported but with shdl_del = 1.
        [
            'SECTIONNAM' => 'Group', 'SERVICENAM' => 'Individual lesson', 'RESOURCENA' => 'Test Teacher',
            'RESOURCEID' => '4242.00000', 'RESOURCEPH' => '9001112233 (м)',
            'CLIENTNAME' => 'Test Client', 'CLIENTIDX' => '9999.00000', 'CLIENTPHON' => '9007778899 (sms)',
            'CATEGORYNA' => 'Room 1', 'ISGROUP' => false, 'DAYVALUE' => '20260908',
            'TIMEINTERV' => '19:00 - 19:45', 'SEANSEID' => '4.00000', 'STATUSNAME' => 'Отменено клиентом',
        ],
    ]));

    $db->execute(
        'INSERT INTO clubs (clb_id, clb_name, clb_indbf, clb_patchindb, clb_timeindb) VALUES (?, ?, 1, ?, NULL)',
        [900060, 'DBF Test Club', 'C:\\GDPD_db\\test900060.dbf']
    );

    $t->test('importAll creates prepod/Client/schedule rows, applying exclusion filters', function (TestRunner $t) use ($service, $importDir, $db): void {
        $service->importAll($importDir);

        $rows = $db->query('SELECT * FROM schedule WHERE shdl_idclb = 900060 ORDER BY shdl_dtleson');
        $t->assertSame(2, count($rows), 'expected only the 2 non-excluded rows to be imported');

        $t->assertSame('2026-09-07 18:00:00', (string) $rows[0]['shdl_dtleson']);
        $t->assertSame('2026-09-07 18:45:00', (string) $rows[0]['shdl_dtlesoff']);
        $t->assertSame('Individual lesson', $rows[0]['shdl_nameless']);
        $t->assertSame(0, (int) $rows[0]['shdl_del']);
        $t->assertSame(0, (int) $rows[0]['shdl_dtlesend'], 'new rows must start at 0 (not NULL), matching dp.galladance.com pd.php\'s own shdl_dtlesend=0 filter for pending lessons');

        $t->assertSame(1, (int) $rows[1]['shdl_del'], 'the "Отменено клиентом" row should be marked cancelled');

        $teacher = $db->query("SELECT prp_name FROM prepod WHERE prp_phone = '79001112233'")[0];
        $t->assertSame('Test Teacher', $teacher['prp_name']);
        $client = $db->query("SELECT cln_name FROM Client WHERE cln_phone = '79007778899'")[0];
        $t->assertSame('Test Client', $client['cln_name']);

        // The excluded rows' teacher/client must NOT have been created.
        $excludedTeacher = $db->query("SELECT prp_name FROM prepod WHERE prp_phone = '79004445566'");
        $t->assertSame(0, count($excludedTeacher));
    });

    $t->test('re-running importAll without a file change is a no-op (mtime unchanged)', function (TestRunner $t) use ($service, $importDir, $db): void {
        $before = $db->query('SELECT COUNT(*) AS n FROM schedule WHERE shdl_idclb = 900060')[0]['n'];
        $service->importAll($importDir);
        $after = $db->query('SELECT COUNT(*) AS n FROM schedule WHERE shdl_idclb = 900060')[0]['n'];
        $t->assertSame($before, $after);
    });

    // --- cleanup ---------------------------------------------------------
    $db->execute('DELETE FROM schedule WHERE shdl_idclb = 900060');
    $db->execute('DELETE FROM clubs WHERE clb_id = 900060');
    $db->execute("DELETE FROM prepod WHERE prp_phone = '79001112233'");
    $db->execute("DELETE FROM Client WHERE cln_phone = '79007778899'");
    @unlink($dbfPath);
    @rmdir($importDir);
};
