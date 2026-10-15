<?php

declare(strict_types=1);

use Gdpd\Infrastructure\NameFormatting;

return function (TestRunner $t): void {
    $t->group('NameFormatting');

    // All inputs below are real cln_name values from production.
    $cases = [
        ['ПЕТРАЧЕНКО ЛАРИСА БОРИСОВНА', 'Петраченко Лариса Борисовна'],
        ['ШАЙДАЕВ М.М. ', 'Шайдаев М.М.'],
        ['ГЛОТОВА  С.Д. ', 'Глотова С.Д.'],
        ['ГАЛУСТЯН AFB Е.З. ', 'Галустян AFB Е.З.'],
        ['ЛИПАТОВА АННА *', 'Липатова Анна *'],
        ['ТЕР-ГАЗАРЯН КАРИНА', 'Тер-Газарян Карина'],
        ['САЗОНОВА ЮЛИЯ  АНДРЕЕВНА', 'Сазонова Юлия Андреевна'],
        ['ЁЛКИНА ЕЛЕНА', 'Ёлкина Елена'],
    ];
    foreach ($cases as [$in, $expected]) {
        $t->test("all-caps \"{$in}\" becomes \"{$expected}\"", function (TestRunner $t) use ($in, $expected): void {
            $t->assertSame($expected, NameFormatting::fromAllCaps($in));
        });
    }

    $untouched = [
        'Кулакова Екатерина Петровна',
        'Пужай-Череда Виктория Васильевна',
        'Д агостино Анаи Карловна',
        'no name 2',
        '9213134573 (sms)',
    ];
    foreach ($untouched as $in) {
        $t->test("leaves non-all-caps \"{$in}\" unchanged", function (TestRunner $t) use ($in): void {
            $t->assertSame($in, NameFormatting::fromAllCaps($in));
        });
    }
};
