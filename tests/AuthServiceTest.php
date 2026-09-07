<?php

declare(strict_types=1);

use Gdpd\Data\Db;
use Gdpd\Domain\AuthService;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\SmsGateway;

return function (TestRunner $t): void {
    $t->group('AuthService (MySQL integration)');

    try {
        $db = new Db('127.0.0.1', 3306, 'gdpd_dev', 'root', '');
    } catch (\Throwable $e) {
        $t->test('skipped: local MySQL "gdpd_dev" not reachable', function () use ($e): void {
            throw new \RuntimeException($e->getMessage());
        });
        return;
    }

    $log = new Logger(sys_get_temp_dir() . '/gdpd_php_tests');
    $config = new Config(sys_get_temp_dir() . '/gdpd_php_tests_missing_config.ini'); // no [sms] section -> gateway not configured, matching an empty apikey/apiurl deploy
    $sms = new SmsGateway($config, $log);
    $auth = new AuthService($db, $sms, $config, $log);

    $db->execute('DELETE FROM prepod WHERE prp_id = 900020');
    $db->execute('INSERT INTO prepod (prp_id, prp_name, prp_phone, prp_pass, prp_out) VALUES (900020, ?, ?, ?, 0)', [
        'Login Test Teacher', '79000000030', 'secret123',
    ]);

    $t->test('getAuthor with correct login/pass returns only prp_id', function (TestRunner $t) use ($auth): void {
        $json = $auth->getAuthor(['login' => '79000000030', 'pass' => 'secret123']);
        $decoded = json_decode($json, true);
        $t->assertSame([['prp_id' => '900020']], $decoded);
    });

    $t->test('getAuthor with wrong password returns "no"', function (TestRunner $t) use ($auth): void {
        $t->assertSame('no', $auth->getAuthor(['login' => '79000000030', 'pass' => 'wrong']));
    });

    $t->test('getAuthor with no body returns "no"', function (TestRunner $t) use ($auth): void {
        $t->assertSame('no', $auth->getAuthor(null));
    });

    $t->test('getPass normalizes an 11-digit phone starting with 8', function (TestRunner $t) use ($auth): void {
        // getPass looks the teacher up by their normalized phone and then
        // tries to send SMS; with no SMS gateway configured locally this
        // surfaces as the gateway's own error, which still proves the
        // lookup/normalization succeeded (a truly unmatched phone returns
        // the plain string "no" instead, tested next).
        $result = $auth->getPass(['phone' => '89000000030']);
        $t->assertTrue(str_starts_with($result, 'Error:'), "expected an SMS-gateway error (meaning the phone lookup matched), got: {$result}");
    });

    $t->test('getPass for an unknown phone returns "no"', function (TestRunner $t) use ($auth): void {
        $t->assertSame('no', $auth->getPass(['phone' => '79999999999']));
    });

    $db->execute('DELETE FROM prepod WHERE prp_id = 900020');
};
