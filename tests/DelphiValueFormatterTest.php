<?php

declare(strict_types=1);

use Gdpd\Data\DelphiValueFormatter;

/**
 * Regression tests pinned to real values captured from the legacy
 * GDPD_apisrv2.exe running against a sandboxed copy of the production
 * database.
 */
return function (TestRunner $t): void {
    $t->group('DelphiValueFormatter');

    $t->test('renders date+time, hour not zero-padded', function (TestRunner $t): void {
        // progress.prgs_id=1: prgs_date_create -> "20.09.2021 11:12:01"
        $t->assertSame('20.09.2021 11:12:01', DelphiValueFormatter::formatDateTime('2021-09-20 11:12:01'));
        // prepod sample shape: "04.12.2024 0:00:11" (hour not zero-padded)
        $t->assertSame('04.12.2024 0:00:11', DelphiValueFormatter::formatDateTime('2024-12-04 00:00:11'));
        $t->assertSame('25.05.2026 6:19:00', DelphiValueFormatter::formatDateTime('2026-05-25 06:19:00'));
    });

    $t->test('renders date-only when time-of-day is exactly midnight', function (TestRunner $t): void {
        // progress.prgs_id=1: prgs_date_cheng, same row/column type as
        // prgs_date_create above, stored value has a midnight time-of-day.
        $t->assertSame('20.09.2021', DelphiValueFormatter::formatDateTime('2021-09-20 00:00:00'));
        // purpose.clpp_id=157: clpp_dateon -> "05.04.2022"
        $t->assertSame('05.04.2022', DelphiValueFormatter::formatDateTime('2022-04-05 00:00:00'));
    });

    $t->test('boolean renders as capitalized True/False', function (TestRunner $t): void {
        $t->assertSame('False', DelphiValueFormatter::formatValue(0, true, false));
        $t->assertSame('True', DelphiValueFormatter::formatValue(1, true, false));
    });

    $t->test('parseBooleanInput accepts the "True"/"False" shape formatValue produces, case-insensitively', function (TestRunner $t): void {
        $t->assertSame('1', DelphiValueFormatter::parseBooleanInput('true'));
        $t->assertSame('1', DelphiValueFormatter::parseBooleanInput('True'));
        $t->assertSame('0', DelphiValueFormatter::parseBooleanInput('false'));
        $t->assertSame('0', DelphiValueFormatter::parseBooleanInput('False'));
        $t->assertSame('0', DelphiValueFormatter::parseBooleanInput(''));
        $t->assertSame('1', DelphiValueFormatter::parseBooleanInput('1'));
        $t->assertSame('0', DelphiValueFormatter::parseBooleanInput('0'));
    });

    $t->test('null renders as empty string', function (TestRunner $t): void {
        $t->assertSame('', DelphiValueFormatter::formatValue(null, false, false));
    });

    $numberCases = [
        [1500.0, '1500'],
        [1500.5, '1500,5'],
        [-0.15, '-0,15'],
        [0.0, '0'],
    ];
    foreach ($numberCases as [$value, $expected]) {
        $t->test("number {$value} uses comma separator, no trailing zeros", function (TestRunner $t) use ($value, $expected): void {
            $t->assertSame($expected, DelphiValueFormatter::formatNumber($value));
        });
    }

    $t->test('parseDateTimeInput accepts the same dd.MM.yyyy shape the API reads back out', function (TestRunner $t): void {
        $t->assertSame('2026-09-07 00:00:00', DelphiValueFormatter::parseDateTimeInput('07.09.2026'));
        $t->assertSame('2026-09-07 11:12:01', DelphiValueFormatter::parseDateTimeInput('07.09.2026 11:12:01'));
        // ajax/newCreate.php sends dates via PHP's date('d.m.Y H:i:s'),
        // which zero-pads the hour -- make sure that round-trips too.
        $t->assertSame('2026-09-07 06:19:00', DelphiValueFormatter::parseDateTimeInput('07.09.2026 06:19:00'));
    });

    $t->test('parseDateTimeInput returns null for empty or unparseable input', function (TestRunner $t): void {
        $t->assertSame(null, DelphiValueFormatter::parseDateTimeInput(''));
        $t->assertSame(null, DelphiValueFormatter::parseDateTimeInput('not a date'));
    });

    $t->test('integer and string pass through unchanged', function (TestRunner $t): void {
        $t->assertSame('491', DelphiValueFormatter::formatValue(491, false, false));
        $t->assertSame('AGUILAR NADIA *', DelphiValueFormatter::formatValue('AGUILAR NADIA *', false, false));
    });
};
