<?php

declare(strict_types=1);

use Gdpd\Infrastructure\PhoneFormatting;

return function (TestRunner $t): void {
    $t->group('PhoneFormatting');

    $t->test('insertSalaryPhoneDashes matches ground truth from old server', function (TestRunner $t): void {
        // Confirmed via /getactsal on the sandboxed legacy server for
        // prepod.prp_phone "79265990131" -> "Phone":"7-926-599-01-31".
        $t->assertSame('7-926-599-01-31', PhoneFormatting::insertSalaryPhoneDashes('79265990131'));
    });

    $t->test('getDigits keeps only digits and commas', function (TestRunner $t): void {
        $t->assertSame('79265990131', PhoneFormatting::getDigits('+7 (926) 599-01-31'));
        $t->assertSame('7,926,599', PhoneFormatting::getDigits('7,926,599'));
    });
};
