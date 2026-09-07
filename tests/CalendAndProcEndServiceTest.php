<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Domain\CalendService;
use Gdpd\Domain\ProcEndService;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('CalendService / ProcEndService (MySQL integration)');

    try {
        $db = new Db('127.0.0.1', 3306, 'gdpd_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $calend = new CalendService($db, $log);
    $procEnd = new ProcEndService($db, $log);

    // --- calend fixtures --------------------------------------------
    $db->execute('DELETE FROM Client WHERE cln_id = 900040');
    $db->execute('DELETE FROM prepod WHERE prp_id = 900040');
    $db->execute('DELETE FROM LessType WHERE lt_id = 900040');
    $db->execute('DELETE FROM dancetype WHERE dt_id = 900040');
    $db->execute('DELETE FROM DP_blok1_less WHERE pb1_idcln = 900040');

    $db->execute('INSERT INTO Client (cln_id, cln_name, cln_phone) VALUES (900040, ?, ?)', ['Calend Client', '79000000050']);
    $db->execute('INSERT INTO prepod (prp_id, prp_name) VALUES (900040, ?)', ['Calend Teacher']);
    $db->execute('INSERT INTO LessType (lt_id, it_name) VALUES (900040, ?)', ['Individual']);
    $db->execute('INSERT INTO dancetype (dt_id, dt_name, dt_color) VALUES (900040, ?, ?)', ['Latin', '#ff0000']);
    $db->execute(
        'INSERT INTO DP_blok1_less (pb1_idcln, pb1_idprp, pb1_idlt, pb1_iddt, pb1_datles, pb1_aboutless, pb1_recom)
         VALUES (900040, 900040, 900040, 900040, ?, ?, ?)',
        ['2026-09-07 18:00:00', "Line one\\nLine two", 'Keep practicing']
    );

    $t->test('getCalend returns the fixed field shape with duplicated keys', function (TestRunner $t) use ($calend): void {
        $json = $calend->getCalend(['cln_phone' => '79000000050']);
        $rows = json_decode($json, true);
        $t->assertSame(1, count($rows));
        $row = $rows[0];
        $t->assertSame('Latin', $row['name']);
        $t->assertSame('Latin', $row['dns_name']); // duplicated under a different key, same as the original
        $t->assertSame('Individual', $row['lesstype']);
        $t->assertSame('Individual', $row['it_name']);
        $t->assertSame('Calend Teacher', $row['prepod']);
        $t->assertSame('Calend Teacher', $row['prp_name']);
        $t->assertSame('#ff0000', $row['color']);
        $t->assertSame('Keep practicing', $row['recom']);
        // Real bug caught by diffing live output against the legacy
        // server: this endpoint builds JSON by hand (not via RowFormatter)
        // and had briefly skipped date formatting, leaking MySQL's raw
        // "Y-m-d H:i:s" shape instead of "dd.MM.yyyy H:mm:ss".
        $t->assertSame('07.09.2026 18:00:00', $row['lessdate']);
        $t->assertSame('07.09.2026 18:00:00', $row['prgs_date_create']);
    });

    $t->test('getCalend un-doubles a literal backslash-n into a JSON newline escape', function (TestRunner $t) use ($calend): void {
        $json = $calend->getCalend(['cln_phone' => '79000000050']);
        // The raw JSON text should contain a single-backslash "\n" escape
        // (which decodes to a real newline), not a doubled "\\n".
        $t->assertTrue(str_contains($json, 'Line one\\nLine two'), "expected a single JSON newline escape, got: {$json}");
        $t->assertTrue(!str_contains($json, 'Line one\\\\nLine two'), "expected no doubled backslash, got: {$json}");
        $decoded = json_decode($json, true);
        $t->assertSame("Line one\nLine two", $decoded[0]['info']);
    });

    $t->test('getCalend with no cln_phone is an error', function (TestRunner $t) use ($calend): void {
        $t->assertSame('ERROR: Invalid parameter', $calend->getCalend(['other' => '1']));
    });

    // --- procend fixtures ---------------------------------------------
    $db->execute('DELETE FROM figura WHERE fgr_id IN (900041, 900042)');
    $db->execute('DELETE FROM dance WHERE dns_id = 900041');
    $db->execute('DELETE FROM lvl WHERE lvl_id = 900041');
    $db->execute('DELETE FROM DP_blok2_figur WHERE pb2_idcln = 900040');

    $db->execute('INSERT INTO lvl (lvl_id, lvl_name) VALUES (900041, ?)', ['Gold']);
    $db->execute('INSERT INTO dance (dns_id, dns_name, id_dt) VALUES (900041, ?, 900040)', ['Rumba']);
    $db->execute('INSERT INTO figura (fgr_id, fgr_iddance, fgr_level, fgr_name) VALUES (900041, 900041, 900041, ?), (900042, 900041, 900041, ?)', ['Basic', 'Advanced']);
    // Client 900040 has completed only the first figure (pb2_datoff set).
    $db->execute('INSERT INTO DP_blok2_figur (pb2_idcln, pb2_idfigur, pb2_daton, pb2_datoff) VALUES (900040, 900041, ?, ?)', ['2026-01-01', '2026-02-01']);

    $t->test('getProcEnd computes completed/total as a percentage', function (TestRunner $t) use ($procEnd): void {
        // Real clients (galladance.com's proxy) send "fgr_level", the
        // correctly-spelled column name -- and the legacy server's request
        // key check for this filter has a typo ("frg_level") that never
        // matches it, so this filter is a no-op in real traffic. Confirmed
        // here by using the real key and expecting the level NOT to be
        // applied (dt_id+dns_id alone already narrow to exactly these two
        // figures in this fixture, so the percentage is the same either
        // way -- the dedicated test below proves the typo more directly).
        $json = $procEnd->getProcEnd(['dt_id' => 900040, 'dns_id' => 900041, 'fgr_level' => 900041, 'cln_phone' => '79000000050']);
        $decoded = json_decode($json, true);
        $t->assertTrue($decoded !== null, "expected valid JSON, got: {$json}");
        // 1 of 2 figures done -> 50%, formatted with a comma per the ru-RU
        // number convention used throughout this API.
        $t->assertSame('50,00%', $decoded[0]['proc']);
    });

    $t->test('getProcEnd ignores "fgr_level" (legacy key typo, kept for fidelity)', function (TestRunner $t) use ($procEnd): void {
        // A level that matches nothing should still return the same 50%
        // as above, proving the level filter never actually applied.
        $json = $procEnd->getProcEnd(['dt_id' => 900040, 'dns_id' => 900041, 'fgr_level' => 999999, 'cln_phone' => '79000000050']);
        $decoded = json_decode($json, true);
        $t->assertSame('50,00%', $decoded[0]['proc']);
    });

    $t->test('getProcEnd without a phone returns the no-space "Error:" form', function (TestRunner $t) use ($procEnd): void {
        $result = $procEnd->getProcEnd(['dt_id' => 900040]);
        $t->assertTrue(str_starts_with($result, 'Error:'), $result);
        $t->assertTrue(!str_starts_with($result, 'Error: '), 'expected no space after the colon, matching the legacy quirk');
    });

    // --- cleanup --------------------------------------------------------
    $db->execute('DELETE FROM DP_blok2_figur WHERE pb2_idcln = 900040');
    $db->execute('DELETE FROM figura WHERE fgr_id IN (900041, 900042)');
    $db->execute('DELETE FROM dance WHERE dns_id = 900041');
    $db->execute('DELETE FROM lvl WHERE lvl_id = 900041');
    $db->execute('DELETE FROM DP_blok1_less WHERE pb1_idcln = 900040');
    $db->execute('DELETE FROM dancetype WHERE dt_id = 900040');
    $db->execute('DELETE FROM LessType WHERE lt_id = 900040');
    $db->execute('DELETE FROM prepod WHERE prp_id = 900040');
    $db->execute('DELETE FROM Client WHERE cln_id = 900040');
};
