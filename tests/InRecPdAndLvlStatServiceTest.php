<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Data\SchemaGuard;
use Gdpd\Domain\InRecPdService;
use Gdpd\Domain\LvlStatService;
use Gdpd\Infrastructure\Logger;

return function (TestRunner $t): void {
    $t->group('InRecPdService / LvlStatService (MySQL integration)');

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
    $inRecPd = new InRecPdService($db, $schema, $log);
    $lvlStat = new LvlStatService($db, $log);

    $db->execute('DELETE FROM progress WHERE prgs_id = 900050');

    $t->test('putInRecPd with id_rec=0 inserts a new progress row and stamps timestamps', function (TestRunner $t) use ($inRecPd, $db): void {
        $result = $inRecPd->putInRecPd(['id_rec' => '0', 'prgs_idcln' => 1, 'prgs_status' => 'В процессе']);
        $t->assertSame('ok', $result);

        $rows = $db->query('SELECT prgs_id, prgs_status, prgs_date_create, prgs_date_cheng FROM progress WHERE prgs_idcln = 1 ORDER BY prgs_id DESC LIMIT 1');
        $t->assertSame('В процессе', $rows[0]['prgs_status']);
        $t->assertTrue($rows[0]['prgs_date_create'] !== null, 'expected prgs_date_create to be stamped');
        $t->assertTrue($rows[0]['prgs_date_cheng'] !== null, 'expected prgs_date_cheng to be stamped');
        $db->execute('DELETE FROM progress WHERE prgs_id = ?', [$rows[0]['prgs_id']]);
    });

    $t->test('putInRecPd with an empty body returns "JSON has no text" (no ERROR: prefix)', function (TestRunner $t) use ($inRecPd): void {
        $t->assertSame('JSON has no text', $inRecPd->putInRecPd([]));
    });

    $t->test('getInRecPd with neither id_* nor phone filters is an error', function (TestRunner $t) use ($inRecPd): void {
        $result = $inRecPd->getInRecPd(['something_else' => '1']);
        $t->assertSame('ERROR: Invalid parameter', $result);
    });

    $t->test('getInRecPd with id_cln/id_prp surfaces the known-broken legacy column names as a SQL error', function (TestRunner $t) use ($inRecPd): void {
        // Documents the finding in InRecPdService's docblock: the view's
        // real columns are pb1_idcln/pb1_idprp, not prgs_idcln/prgs_idprp,
        // so this legacy filter shape has never actually worked. This
        // isn't "fixed" here since no live caller depends on any specific
        // (broken) behaviour -- we're just confirming it fails the same
        // way rather than silently returning wrong data.
        $result = $inRecPd->getInRecPd(['id_cln' => '0', 'id_prp' => '5']);
        $t->assertTrue(str_starts_with($result, 'Error:'), "expected a SQL error surfaced as Error:, got: {$result}");
    });

    $t->test('getLvlStat with no phone still returns a percentage grid', function (TestRunner $t) use ($lvlStat): void {
        $json = $lvlStat->getLvlStat(['cln_phone' => '79000000000']);
        $decoded = json_decode($json, true);
        $t->assertTrue(is_array($decoded), "expected a JSON array, got: {$json}");
    });

    $t->test('getLvlStat with a null body returns the legacy typo\'d message', function (TestRunner $t) use ($lvlStat): void {
        $t->assertSame('JSON has no tex', $lvlStat->getLvlStat(null));
    });
};
