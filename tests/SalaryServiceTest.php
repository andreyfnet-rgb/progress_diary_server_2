<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Domain\SalaryService;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('SalaryService (MySQL integration)');

    try {
        $salaryDb = new Db('127.0.0.1', 3306, 'gdpd_salary_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_salary_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $service = new SalaryService($salaryDb, $log);

    $phone = '7-926-599-01-31'; // matches PhoneFormatting::insertSalaryPhoneDashes('79265990131')
    $today = new DateTimeImmutable('today');
    // Real Access data stores the week key with no leading zero on the week
    // number ("92026", not "092026") -- date('W') always zero-pads, so this
    // must strip it the same way SalaryService::weekYearKey() does.
    $weekKey = ((int) $today->format('W')) . $today->format('Y');

    $salaryDb->execute('DELETE FROM wv_stpprep_week WHERE phone = ?', [$phone]);
    $salaryDb->execute('DELETE FROM wv_datein_group_week WHERE phone = ?', [$phone]);

    // Stavka group "Asist" is id 6.
    $salaryDb->execute(
        'INSERT INTO wv_stpprep_week (sweekno, phone, stv_id, maxpok, midpok, minpok, wherein_1с) VALUES (?, ?, 6, 500, 400, 300, ?)',
        [$weekKey, $phone, 'schedule']
    );
    $salaryDb->execute(
        'INSERT INTO wv_datein_group_week (pweekno, phone, id, spok) VALUES (?, ?, 6, 4)',
        [$weekKey, $phone]
    );

    $t->test('getDatSal with no dates defaults to the current week and returns the Asist group', function (TestRunner $t) use ($service, $phone): void {
        $json = $service->getDatSal(['phone' => '79265990131']);
        $decoded = json_decode($json, true);
        $t->assertTrue($decoded !== null, "expected valid JSON, got: {$json}");
        $t->assertSame('79265990131', $decoded['prepod']['Phone']);
        $t->assertSame('6', $decoded['Asist']['stavkaid']);
        $t->assertSame('300', $decoded['Asist']['minstv']);
        $t->assertSame('4', $decoded['Asist']['Datein']);
        // No fixture data for group/gonorar/Plan1/Plan2 -> empty strings, not errors.
        $t->assertSame('', $decoded['group']['Datein']);
    });

    $t->test('getDatSal matches a week 1-9 key with no leading zero (real Access data has no padding)', function (TestRunner $t) use ($service, $salaryDb, $phone): void {
        // Confirmed against a real export of 1cdbgdsweek1c.mdb: week 9 of
        // 2026 is stored as sweekno "92026", not "092026". PHP's date('W')
        // always zero-pads single-digit weeks, so this regresses if
        // SalaryService ever goes back to using it directly.
        $salaryDb->execute('DELETE FROM wv_stpprep_week WHERE phone = ? AND sweekno = ?', [$phone, '92026']);
        $salaryDb->execute(
            'INSERT INTO wv_stpprep_week (sweekno, phone, stv_id, maxpok, midpok, minpok) VALUES (?, ?, 6, 500, 400, 300)',
            ['92026', $phone]
        );

        $json = $service->getDatSal(['phone' => '79265990131', 'date_stv' => '01.03.2026', 'date_pok' => '01.03.2026']);
        $decoded = json_decode($json, true);
        $t->assertTrue($decoded !== null, "expected valid JSON, got: {$json}");
        $t->assertSame('6', $decoded['Asist']['stavkaid'], 'week key must match "92026" (no leading zero), not "092026"');

        $salaryDb->execute('DELETE FROM wv_stpprep_week WHERE phone = ? AND sweekno = ?', [$phone, '92026']);
    });

    $t->test('getDatSal requires a phone', function (TestRunner $t) use ($service): void {
        $t->assertSame('ERROR: phone is required', $service->getDatSal(['phone' => '']));
    });

    $t->test('getDatSal rejects a too-short phone', function (TestRunner $t) use ($service): void {
        $t->assertSame('ERROR: phone number is too short', $service->getDatSal(['phone' => '12345']));
    });

    $t->test('getDatSal with a null body is an error', function (TestRunner $t) use ($service): void {
        $t->assertSame('ERROR: request has no JSON body', $service->getDatSal(null));
    });

    $salaryDb->execute('DELETE FROM wv_stpprep_week WHERE phone = ?', [$phone]);
    $salaryDb->execute('DELETE FROM wv_datein_group_week WHERE phone = ?', [$phone]);
};
