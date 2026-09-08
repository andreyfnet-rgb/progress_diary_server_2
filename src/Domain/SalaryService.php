<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Data\Db;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\PhoneFormatting;

/**
 * Ports get_dat_sal (SrvMetod.pas) -- confirmed as a live consumer via
 * galladance.com's local/api/dp/index.php, which calls our /getdatsal and
 * then does its own (fairly involved) arithmetic on the raw
 * stavkaid/wherein1c/maxstv/midstv/minstv/Datein fields this returns, so
 * the response shape must stay exactly as the legacy server produced it.
 *
 * Reads from the SEPARATE salary database (1cdbgdsweek1c.mdb in the
 * original, "gdpd_salary_dev" here) rather than the main one -- see
 * migrations/salary_schema.sql for why wv_stpprep_week/wv_datein_group_week
 * are plain tables here (refreshed by a daily export from Access) instead
 * of reimplemented as MySQL views.
 *
 * get_act_sal / getdatashow0722 (the more involved salary computation,
 * confirmed via a live A/B test to be the real data source for the mobile
 * app's "Мой доход" screen) is ported separately in SalaryActService --
 * see its docblock.
 */
final class SalaryService
{
    private const STAVKA_GROUPS = [
        'Asist' => '6',
        'individ' => '7,8,34',
        'group' => '9',
        'gonorar' => '19',
        'Plan1' => '20',
        'Plan2' => '33',
    ];

    public function __construct(
        private readonly Db $salaryDb,
        private readonly Logger $log,
    ) {
    }

    public function getDatSal(?array $filter): string
    {
        try {
            if ($filter === null) {
                return 'ERROR: request has no JSON body';
            }
            if (!array_key_exists('phone', $filter) || JsonValue::toString($filter['phone']) === '') {
                return 'ERROR: phone is required';
            }

            $digits = PhoneFormatting::getDigits(JsonValue::toString($filter['phone']));
            if (strlen($digits) < 11) {
                return 'ERROR: phone number is too short';
            }
            $phone = PhoneFormatting::insertSalaryPhoneDashes($digits);

            $dateStv = $this->resolveDate($filter, 'date_stv');
            if ($dateStv === false) {
                return 'ERROR: invalid date_stv';
            }
            $datePok = $this->resolveDate($filter, 'date_pok');
            if ($datePok === false) {
                return 'ERROR: invalid date_pok';
            }

            $result = [
                'prepod' => ['Phone' => JsonValue::toString($filter['phone'])],
                'period' => [
                    'stv_begin' => $this->mondayOfWeek($dateStv)->format('d.m.Y'),
                    'stv_end' => $this->mondayOfWeek($dateStv)->modify('+6 days')->format('d.m.Y'),
                    'pok_begin' => $this->mondayOfWeek($datePok)->format('d.m.Y'),
                    'pok_end' => $this->mondayOfWeek($datePok)->modify('+6 days')->format('d.m.Y'),
                ],
            ];

            foreach (self::STAVKA_GROUPS as $key => $stavkaIds) {
                $result[$key] = $this->queryGroup($stavkaIds, $phone, $dateStv, $datePok);
            }

            $json = json_encode($result, JSON_UNESCAPED_UNICODE);
            return $json === false ? 'Error: failed to encode result' : $json;
        } catch (\Throwable $e) {
            $this->log->log('ERROR_getdattabsal', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /** @return array{stavkaid: string, wherein1c: string, maxstv: string, midstv: string, minstv: string, Datein: string} */
    private function queryGroup(string $stavkaIdCsv, string $phone, \DateTimeImmutable $dateStv, \DateTimeImmutable $datePok): array
    {
        $stavkaIds = array_map('trim', explode(',', $stavkaIdCsv));
        $placeholders = implode(', ', array_fill(0, count($stavkaIds), '?'));
        $sweekno = self::weekYearKey($dateStv);
        $pweekno = self::weekYearKey($datePok);

        $stavkaid = '';
        $wherein1c = '';
        $maxstv = '';
        $midstv = '';
        $minstv = '';
        $datein = '';

        $rateRows = $this->salaryDb->query(
            "SELECT * FROM wv_stpprep_week WHERE stv_id IN ({$placeholders}) AND phone = ? AND sweekno = ?",
            [...$stavkaIds, $phone, $sweekno]
        );
        foreach ($rateRows as $row) {
            $stavkaid = self::addField($stavkaid, (string) $row['stv_id']);
            $wherein1c = self::addField($wherein1c, (string) ($row['wherein_1с'] ?? ''));
            $maxstv = self::addField($maxstv, (string) ($row['maxpok'] ?? ''));
            $midstv = self::addField($midstv, (string) ($row['midpok'] ?? ''));
            $minstv = self::addField($minstv, (string) ($row['minpok'] ?? ''));
        }

        $countRows = $this->salaryDb->query(
            "SELECT * FROM wv_datein_group_week WHERE id IN ({$placeholders}) AND phone = ? AND pweekno = ?",
            [...$stavkaIds, $phone, $pweekno]
        );
        foreach ($countRows as $row) {
            $spok = $row['spok'] ?? null;
            $datein = self::addField($datein, $spok === null || $spok === '' ? '0' : (string) $spok);
        }

        return [
            'stavkaid' => $stavkaid,
            'wherein1c' => $wherein1c,
            'maxstv' => $maxstv,
            'midstv' => $midstv,
            'minstv' => $minstv,
            'Datein' => $datein,
        ];
    }

    /** Mirrors the original's `addff`: joins with ", " unless the accumulator is still empty. */
    private static function addField(string $field, string $addition): string
    {
        return $field === '' ? $addition : $field . ', ' . $addition;
    }

    /**
     * Matches the original's IntToStr(WeekOfTheYear(d)) + IntToStr(YearOf(d))
     * exactly: no leading zero on the week number ("92026", not "092026").
     * PHP's date('W') always zero-pads to 2 digits, which silently failed
     * to match anything for weeks 1-9 of any year until caught against
     * real salary data for an early-year week.
     */
    private static function weekYearKey(\DateTimeImmutable $date): string
    {
        return ((int) $date->format('W')) . $date->format('Y');
    }

    private function mondayOfWeek(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('monday this week');
    }

    private function resolveDate(array $filter, string $key): \DateTimeImmutable|false
    {
        if (!array_key_exists($key, $filter) || JsonValue::toString($filter[$key]) === '') {
            return new \DateTimeImmutable('today');
        }

        $parsed = DelphiValueFormatter::parseDateTimeInput(JsonValue::toString($filter[$key]));
        if ($parsed === null) {
            return false;
        }

        return new \DateTimeImmutable($parsed);
    }
}
