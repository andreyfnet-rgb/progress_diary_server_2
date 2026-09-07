<?php

declare(strict_types=1);

use Gdpd\Infrastructure\ArithmeticExpressionEvaluator;

return function (TestRunner $t): void {
    $t->group('ArithmeticExpressionEvaluator');

    $cases = [
        ['1+2', 3.0],
        ['2*3+4', 10.0],
        ['2+3*4', 14.0],
        ['(2+3)*4', 20.0],
        ['10/4', 2.5],
        ['-5+3', -2.0],
        ['1000-1', 999.0],
        ['1.5+2.5', 4.0],
        // Delphi's CurrToStr/StrToCurr run under a ru-RU locale and use ','
        // as the decimal separator, so the evaluator must accept it too.
        ['1,5+2,5', 4.0],
        ['(1+2)*(3+4)', 21.0],
        // The one real production formula found (stavka.ID=24, raschet=true).
        ['6+7+8+9+10+11+12', 63.0],
    ];
    foreach ($cases as [$expr, $expected]) {
        $t->test("evaluates \"{$expr}\"", function (TestRunner $t) use ($expr, $expected): void {
            $t->assertSame($expected, ArithmeticExpressionEvaluator::evaluate($expr));
        });
    }

    foreach (['1+', '(1+2', '1+*2', 'abc'] as $bad) {
        $t->test("rejects malformed \"{$bad}\"", function (TestRunner $t) use ($bad): void {
            $t->assertThrows(fn() => ArithmeticExpressionEvaluator::evaluate($bad));
        });
    }
};
