<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Phone-number helpers ported from dateunit.pas / SrvMetod.pas. Only the
 * pieces actually reachable from the HTTP API are ported (numphone/numonly
 * in the legacy dateunit.pas are never called from SrvMetod.pas and are
 * left out, same as the dead sendsms_old SMS path).
 */
final class PhoneFormatting
{
    /**
     * Mirrors dateunit.pas's get_digits: keeps only digits and commas,
     * dropping everything else (spaces, parentheses, '+', '-', ...).
     */
    public static function getDigits(string $value): string
    {
        return preg_replace('/[^0-9,]/', '', $value) ?? '';
    }

    /**
     * Mirrors the inline dash-insertion loop duplicated in
     * get_act_sal/get_dat_sal: inserts '-' immediately before the
     * characters at (1-based) positions 2, 5, 8 and 10. For an 11-digit
     * Russian phone number like "79265990131" this produces
     * "7-926-599-01-31" -- confirmed against the real server's
     * /getactsal response for prepod.prp_phone "79265990131". The loop is
     * generic over the input length, same as the original -- it does not
     * require exactly 11 characters.
     */
    public static function insertSalaryPhoneDashes(string $digits): string
    {
        $dashPositions = [2 => true, 5 => true, 8 => true, 10 => true];
        $result = '';
        $length = strlen($digits);
        for ($i = 1; $i <= $length; $i++) {
            if (isset($dashPositions[$i])) {
                $result .= '-';
            }
            $result .= $digits[$i - 1];
        }

        return $result;
    }
}
