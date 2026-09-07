<?php

declare(strict_types=1);

namespace Gdpd\Domain;

/**
 * Mirrors Delphi's TJSONValue.Value: returns the string form of any JSON
 * scalar regardless of its declared JSON type (string/number/bool),
 * because the legacy code reads every request field with
 * ij.GetValue(key).Value without checking its JSON type first. Real client
 * code sends a mix -- mostly strings, but also raw JSON numbers (e.g.
 * {"shdl_dtlesend":0} in ajax/newCreate.php on dp.galladance.com).
 */
final class JsonValue
{
    public static function toString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
