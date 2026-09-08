<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Domain\SalaryActService;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('SalaryActService (MySQL integration)');

    try {
        $salaryDb = new Db('127.0.0.1', 3306, 'gdpd_salary_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_salary_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $service = new SalaryActService($salaryDb, $log);

    $phone = '7-000-000-00-01';
    $today = new DateTimeImmutable('today');
    $weekStart = $today->modify('monday this week');
    $d1 = $weekStart->format('Y-m-d 00:00:00');

    // Fixtures below use stavka ids up to 100 (real production data only
    // goes up to 34 -- the higher ids are unused "helper" rows that steer
    // pv/pz1/pz2 without appearing in any addjo group). This table is
    // fully owned by this test (unlike the phone-scoped deletes elsewhere)
    // because synthetic ids overlap the real 1-34 range; if you've loaded
    // real data via tools/import-salary-csv.php for manual endpoint
    // testing, re-run it afterward to restore stavka.
    $salaryDb->execute('DELETE FROM stavka');
    for ($id = 1; $id <= 100; $id++) {
        $salaryDb->execute('INSERT INTO stavka (id) VALUES (?)', [$id]);
    }

    $cleanup = function () use ($salaryDb, $phone): void {
        $salaryDb->execute('DELETE FROM wv_prepod WHERE phone = ?', [$phone]);
        $salaryDb->execute('DELETE FROM wv_stvprep WHERE idprep BETWEEN 90000 AND 90099');
        $salaryDb->execute('DELETE FROM wv_datain WHERE idprep BETWEEN 90000 AND 90099');
    };
    $cleanup();

    /**
     * @param array{idstav:int, minpok?:int|null, midpok?:int|null, maxpok?:int|null, mnojstv?:string, trio?:bool, plan?:int|null, raschet?:bool} $row
     */
    $insertStvprep = function (Db $db, int $idprep, array $row) use ($d1): void {
        $db->execute(
            'INSERT INTO wv_stvprep (idprep, idstav, datado, minpok, midpok, maxpok, mnojstv, trio, plan, raschet) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $idprep,
                $row['idstav'],
                $d1,
                $row['minpok'] ?? null,
                $row['midpok'] ?? null,
                $row['maxpok'] ?? null,
                $row['mnojstv'] ?? '',
                (int) ($row['trio'] ?? false),
                $row['plan'] ?? null,
                (int) ($row['raschet'] ?? false),
            ]
        );
    };
    $insertDatain = function (Db $db, int $idprep, int $idstv, float $pok, bool $sumplan) use ($d1): void {
        $db->execute(
            'INSERT INTO wv_datain (idprep, idstv, dataindo, pok, sumplan) VALUES (?,?,?,?,?)',
            [$idprep, $idstv, $d1, $pok, (int) $sumplan]
        );
    };
    $insertPrepod = function (Db $db, int $idprep, string $phone, string $club) {
        $db->execute('INSERT INTO wv_prepod (prepod_id, phone, nameclb) VALUES (?,?,?)', [$idprep, $phone, $club]);
    };
    $runOne = function (Db $db, SalaryActService $service, string $phone) {
        $json = $service->getActSal(['phone' => str_replace('-', '', $phone)]);
        $decoded = json_decode($json, true);
        return [$json, $decoded];
    };

    $t->test('phone is required', function (TestRunner $t) use ($service): void {
        $t->assertSame('ERROR: phone is required', $service->getActSal(['phone' => '']));
    });

    $t->test('too-short phone is rejected', function (TestRunner $t) use ($service): void {
        $t->assertSame('ERROR: phone number is too short', $service->getActSal(['phone' => '12345']));
    });

    $t->test('null body is an error', function (TestRunner $t) use ($service): void {
        $t->assertSame('ERROR: request has no JSON body', $service->getActSal(null));
    });

    $t->test('no wv_prepod rows for phone -> empty array', function (TestRunner $t) use ($service): void {
        $json = $service->getActSal(['phone' => '79999999999']);
        $t->assertSame('[]', $json);
    });

    $t->test('min == max, plain multiplier (no special mnojstv)', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $insertDatain, $runOne, $cleanup): void {
        $idprep = 90001;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 10, 'maxpok' => 10]);
        $insertDatain($salaryDb, $idprep, 6, 5.0, false);

        [$json, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertTrue($d !== null, "expected valid JSON, got: {$json}");
        $t->assertSame('10', $d[0]['asist']['stavkav']);
        $t->assertSame('5', $d[0]['asist']['Pokazael']);
        $t->assertSame('50', $d[0]['asist']['summa'], 'trunc(10 * 5)');

        $cleanup();
    });

    $t->test('min == 0 picks maxpok', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90002;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 0, 'maxpok' => 250]);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('250', $d[0]['asist']['stavkav']);
        $t->assertSame('0', $d[0]['asist']['summa'], 'no wv_datain row -> pokaz defaults to 0');

        $cleanup();
    });

    $t->test('max == 0 (min nonzero) picks minpok', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90003;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 180, 'maxpok' => 0]);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('180', $d[0]['asist']['stavkav']);

        $cleanup();
    });

    $t->test("mnojstv '%' computes trunc(stav * pokaz / 100) and appends '%' to the label", function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $insertDatain, $runOne, $cleanup): void {
        $idprep = 90004;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        // 'onlain' = position 18 = id 15.
        $insertStvprep($salaryDb, $idprep, ['idstav' => 15, 'minpok' => 50, 'maxpok' => 50, 'mnojstv' => '%']);
        $insertDatain($salaryDb, $idprep, 15, 200.0, false);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('50%', $d[0]['onlain']['stavkav']);
        $t->assertSame('200', $d[0]['onlain']['Pokazael']);
        $t->assertSame('100', $d[0]['onlain']['summa'], 'trunc(50 * 200 / 100)');

        $cleanup();
    });

    $t->test("mnojstv 'const' returns the rate unchanged, ignoring pokaz", function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90005;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 9, 'minpok' => 300, 'maxpok' => 300, 'mnojstv' => 'const']);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('300', $d[0]['group']['stavkav']);
        $t->assertSame('300', $d[0]['group']['summa']);

        $cleanup();
    });

    $t->test("mnojstv '*N' multiplies by the value after the '*'", function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90006;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 14, 'minpok' => 100, 'maxpok' => 100, 'mnojstv' => '*2']);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('100', $d[0]['minigroup']['stavkav']);
        $t->assertSame('200', $d[0]['minigroup']['summa'], 'trunc(100 * 2)');

        $cleanup();
    });

    $t->test('trio, plan target already met this week (pv != 0) uses the min tier', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90007;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        // idstav 99 is a helper row (plan=0) purely to make plandoit0722(...,0,...) = pv != 0;
        // it isn't part of any addjo group so doesn't appear in the response.
        $insertStvprep($salaryDb, $idprep, ['idstav' => 99, 'minpok' => 999, 'plan' => 0]);
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 100, 'midpok' => 200, 'maxpok' => 300, 'trio' => true]);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('100/200/300', $d[0]['asist']['stavkav']);
        $t->assertSame('0', $d[0]['asist']['summa'], 'min tier * pokaz(0) = 0');

        $cleanup();
    });

    $t->test('trio, plan not yet met (pv == 0), pzs between pz1 and pz2 selects the mid tier', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $insertDatain, $runOne, $cleanup): void {
        $idprep = 90008;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 98, 'minpok' => 500, 'plan' => 1]); // pz1 = 500
        $insertStvprep($salaryDb, $idprep, ['idstav' => 97, 'minpok' => 800, 'plan' => 2]); // pz2 = 800
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 10, 'midpok' => 20, 'maxpok' => 30, 'trio' => true]);
        $insertDatain($salaryDb, $idprep, 6, 650.0, true); // pzs = 650, and also fills pok[6] via the GROUP BY query

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('10/20/30', $d[0]['asist']['stavkav']);
        $t->assertSame('650', $d[0]['asist']['Pokazael']);
        $t->assertSame('13000', $d[0]['asist']['summa'], 'mid tier (20) * pokaz(650)');

        $cleanup();
    });

    $t->test('binary (non-trio), plan target already met (pv != 0) uses the min tier', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90009;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 99, 'minpok' => 777, 'plan' => 0]);
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 50, 'maxpok' => 150]);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('50/150', $d[0]['asist']['stavkav']);

        $cleanup();
    });

    $t->test('binary, pv == 0, pz1 > pzs selects the min tier', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90010;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 98, 'minpok' => 300, 'plan' => 1]); // pz1 = 300, pzs = 0
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 40, 'maxpok' => 90]);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('40/90', $d[0]['asist']['stavkav']);
        $t->assertSame('0', $d[0]['asist']['summa'], 'min(40) * pokaz(0)');

        $cleanup();
    });

    $t->test('binary, pv == 0, pz1 <= pzs selects the max tier', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $insertDatain, $runOne, $cleanup): void {
        $idprep = 90011;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 40, 'maxpok' => 90]);
        $insertDatain($salaryDb, $idprep, 6, 10.0, true); // pzs = 10 (pz1 defaults to 0, 0 <= 10)

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('40/90', $d[0]['asist']['stavkav']);
        $t->assertSame('10', $d[0]['asist']['Pokazael']);
        $t->assertSame('900', $d[0]['asist']['summa'], 'max(90) * pokaz(10)');

        $cleanup();
    });

    $t->test('multi-id category: leading empties are skipped, but an empty after real data leaves a stray "; "', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90012;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        // individ = ids 7, 8, 34 (positions 10, 11, 37); only the middle id (8) has data.
        $insertStvprep($salaryDb, $idprep, ['idstav' => 8, 'minpok' => 99, 'maxpok' => 99]);

        [, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertSame('99; ', $d[0]['individ']['stavkav'], 'matches the original addjo concatenation quirk exactly');
        $t->assertSame('0', $d[0]['individ']['summa']);

        $cleanup();
    });

    $t->test('two clubs for the same phone produce two blocks; a "const" stavka floors sumper via the override query', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep1 = 90020;
        $idprep2 = 90021;
        $insertPrepod($salaryDb, $idprep1, $phone, 'ClubOne');
        $insertStvprep($salaryDb, $idprep1, ['idstav' => 6, 'minpok' => 100, 'maxpok' => 100]); // sumsp = 0 (no pokaz)
        $insertStvprep($salaryDb, $idprep1, ['idstav' => 9, 'minpok' => 50, 'maxpok' => 50, 'mnojstv' => 'const']); // sumsp = 50

        $insertPrepod($salaryDb, $idprep2, $phone, 'ClubTwo');
        $insertStvprep($salaryDb, $idprep2, ['idstav' => 6, 'minpok' => 20, 'maxpok' => 20]);

        [$json, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertTrue($d !== null, "expected valid JSON, got: {$json}");
        $t->assertSame(2, count($d), 'one block per club membership');

        $t->assertSame('ClubOne', $d[0]['prepod']['Club']);
        $t->assertSame('50', $d[0]['prepod']['summa'], 'sumper(0+50) - const(50), then floored back up to const(50)');
        $t->assertSame('50', $d[0]['group']['summa']);

        $t->assertSame('ClubTwo', $d[1]['prepod']['Club']);
        $t->assertSame('0', $d[1]['prepod']['summa']);

        $cleanup();
    });

    $t->test('raschet=1 rows are logged and skipped, not evaluated (and do not fail the request)', function (TestRunner $t) use ($salaryDb, $service, $phone, $insertPrepod, $insertStvprep, $runOne, $cleanup): void {
        $idprep = 90030;
        $insertPrepod($salaryDb, $idprep, $phone, 'ClubA');
        $insertStvprep($salaryDb, $idprep, ['idstav' => 24, 'mnojstv' => 'chek*-0,15', 'raschet' => true]);
        $insertStvprep($salaryDb, $idprep, ['idstav' => 6, 'minpok' => 10, 'maxpok' => 10]);

        [$json, $d] = $runOne($salaryDb, $service, $phone);
        $t->assertTrue($d !== null, "expected valid JSON despite the raschet=1 row, got: {$json}");
        $t->assertSame('10', $d[0]['asist']['stavkav']);

        $cleanup();
    });

    $salaryDb->execute('DELETE FROM stavka');
};
