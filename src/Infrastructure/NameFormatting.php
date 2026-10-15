<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Brings Аклуб/FoxPro-style ALL-CAPS client names ("ПЕТРАЧЕНКО ЛАРИСА
 * БОРИСОВНА", "ШАЙДАЕВ М.М. ") to the "Фамилия Имя Отчество" casing 1C
 * already uses.
 */
final class NameFormatting
{
    /**
     * Only names written entirely in capitals are touched -- anything with
     * a lowercase letter was typed deliberately (1C names, hand fixes) and
     * is returned unchanged, apart from collapsing runs of spaces.
     * Latin-only tokens ("AFB", a club marker seen in real data) keep their
     * case.
     */
    public static function fromAllCaps(string $name): string
    {
        $collapsed = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($collapsed !== mb_strtoupper($collapsed) || !preg_match('/\p{Cyrillic}/u', $collapsed)) {
            return $collapsed;
        }

        $tokens = explode(' ', $collapsed);
        foreach ($tokens as $i => $token) {
            if (preg_match('/\p{Cyrillic}/u', $token)) {
                // Capitalise every letter that follows a non-letter, so
                // initials ("М.М.") and hyphenated surnames ("Тер-Газарян")
                // both come out right -- MB_CASE_TITLE treats "м.м." as one
                // word and gives "М.м.".
                $tokens[$i] = preg_replace_callback(
                    '/(^|[^\p{L}])(\p{L})/u',
                    static fn (array $m): string => $m[1] . mb_strtoupper($m[2]),
                    mb_strtolower($token)
                ) ?? $token;
            }
        }

        return implode(' ', $tokens);
    }
}
