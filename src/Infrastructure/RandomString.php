<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Mirrors dateunit.pas's Randstring(Len, LCase, UpCase, Digit, SpecSymb):
 * only the digits-only shape (Randstring(5, false, false, true, false)) is
 * ever actually used in the legacy code (new teacher/prepod passwords), so
 * that's the only shape ported.
 */
final class RandomString
{
    public static function digits(int $length): string
    {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= (string) random_int(0, 9);
        }
        return $result;
    }
}
