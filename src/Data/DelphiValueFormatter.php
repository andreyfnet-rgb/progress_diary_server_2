<?php

declare(strict_types=1);

namespace Gdpd\Data;

/**
 * Formats MySQL column values exactly the way the legacy Delphi server did
 * when it iterated TDataSet.Fields and called FieldByName(name).AsString
 * to build JSON (see e.g. get_dattab, get_client, get_prep in the old
 * SrvMetod.pas). The old JSON API returns almost everything as strings,
 * and existing consumers (the PHP site, the galladance.com mobile-app
 * proxy) parse those exact string shapes, so this must match byte-for-byte
 * rather than "improve" the typing.
 *
 * These rules were reverse-engineered by running the original compiled
 * server (GDPD_apisrv2.exe) against a sandboxed copy of the real database
 * and comparing its JSON output to the raw column values, rather than
 * guessed from the Delphi source alone:
 *
 * - Date/Time columns: what determines whether the time part is shown is
 *   not the column's declared type but whether the *stored value*'s
 *   time-of-day is exactly midnight. Confirmed side by side on
 *   progress.prgs_date_create (e.g. "20.09.2021 11:12:01") vs
 *   progress.prgs_date_cheng, same table, same column type, a value with a
 *   midnight time-of-day rendered as "20.09.2021" with no time part at
 *   all. This matches Delphi's DateTimeToStr / FormatDateTime('c', ...),
 *   documented to omit the time portion when the value indicates midnight
 *   exactly.
 * - The hour in the time part is NOT zero-padded ("6:19:00", not
 *   "06:19:00") -- confirmed via prepod.prp_info sample values (note:
 *   prp_info itself is a plain text column, not a real date column --
 *   the rule was confirmed on genuine DATETIME columns via
 *   progress.prgs_date_create/prgs_date_cheng instead).
 * - Boolean columns render as "True"/"False" (capitalized) -- confirmed
 *   via prepod.prp_out. In this MySQL port, "boolean" means a column
 *   declared TINYINT(1) in migrations/schema.sql (mirroring the original
 *   Access adBoolean columns) -- see SchemaGuard, which reports this per
 *   column from information_schema so the generic get/put endpoints don't
 *   need per-table special cases.
 * - Decimal/currency values use ',' as the decimal separator, matching the
 *   ru-RU locale the original app runs under. No non-empty example exists
 *   in the main database (it has no currency columns at all), but the
 *   rule is kept for the salary data pulled in from the separate
 *   1cdbgdsweek1c.mdb export, where it does matter (e.g. stavka.mnojstv
 *   containing literals like "chek*-0,15").
 */
final class DelphiValueFormatter
{
    public static function formatValue(mixed $value, bool $isBoolean, bool $isDate): string
    {
        if ($value === null) {
            return '';
        }

        if ($isBoolean) {
            return ((int) $value) !== 0 ? 'True' : 'False';
        }

        if ($isDate) {
            return self::formatDateTime((string) $value);
        }

        if (is_float($value)) {
            return self::formatNumber($value);
        }

        return (string) $value;
    }

    /**
     * Mirrors Delphi's DateTimeToStr / FormatDateTime('c', value): short
     * date, followed by long time UNLESS the time-of-day is exactly
     * midnight, in which case only the date is shown. Day/month/year are
     * zero-padded; the hour is not.
     *
     * @param string $value MySQL DATETIME string, e.g. "2021-09-20 11:12:01" or "2021-09-20 00:00:00"
     */
    public static function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $dateTime = new \DateTimeImmutable($value);
        $datePart = $dateTime->format('d.m.Y');
        if ($dateTime->format('H:i:s') === '00:00:00') {
            return $datePart;
        }

        return $datePart . ' ' . (int) $dateTime->format('H') . $dateTime->format(':i:s');
    }

    /**
     * The write-side counterpart of formatDateTime(): parses a date string
     * in the "dd.MM.yyyy" or "dd.MM.yyyy H:mm:ss" shape -- the same shape
     * this class produces on read, and what dp.galladance.com's PHP code
     * actually sends on write (e.g. ajax/newCreate.php:
     * `$date->format('d.m.Y H:i:s')`) -- into MySQL's "Y-m-d H:i:s".
     * Mirrors Delphi's locale-aware StrToDateTime, which is what the
     * original server relied on when assigning a string straight into a
     * TDateTimeField.
     *
     * Returns null for an empty string (write NULL, matching Delphi
     * treating an empty string assigned to a date field as NULL) or for a
     * genuinely unparseable value (caller decides what to do -- passing
     * the raw string through to MySQL will surface as a clear SQL error
     * rather than silently corrupting data).
     */
    public static function parseDateTimeInput(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
            $dateTime = \DateTime::createFromFormat('!' . $format, $trimmed);
            $errors = \DateTime::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($dateTime !== false && $clean) {
                return $dateTime->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * The write-side counterpart of the "True"/"False" boolean rendering:
     * clients send exactly that shape back (e.g. GDPD_admin's
     * `jo.AddPair('prp_out', 'true')`), which MySQL's TINYINT(1) columns
     * reject outright under the default strict SQL mode ("Incorrect
     * integer value: 'true'") rather than silently coercing it -- caught
     * via a real putdattab call that returned "ok" but never actually
     * flipped the column. Case-insensitive to also accept the capitalized
     * form this same class produces on read.
     */
    public static function parseBooleanInput(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'false' || $normalized === '') {
            return '0';
        }
        if ($normalized === 'true') {
            return '1';
        }

        return ((float) $value) !== 0.0 ? '1' : '0';
    }

    /**
     * Mirrors Delphi's FloatToStr/CurrToStr "general" formatting: variable
     * precision, no trailing zeros, ',' as the decimal separator (ru-RU
     * locale).
     */
    public static function formatNumber(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
        if ($text === '' || $text === '-0') {
            $text = '0';
        }

        return str_replace('.', ',', $text);
    }
}
