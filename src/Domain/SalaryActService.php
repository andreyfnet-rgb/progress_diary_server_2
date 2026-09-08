<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Data\Db;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\PhoneFormatting;

/**
 * Ports get_act_sal / getdatashow0722 (SrvMetod.pas ~lines 1168 / 2586) --
 * confirmed via a live A/B test (old server off -> hang, on -> works) to be
 * the real data source for the mobile app's "Мой доход" screen, called
 * from galladance.com's local/api/fitness/index.php (action=getactsal),
 * NOT get_dat_sal in SalaryService (which dp/index.php calls for a
 * different, simpler display). See migrations/salary_schema.sql for the
 * three extra tables this reads (wv_prepod, wv_datain, wv_stvprep),
 * alongside the already-existing get_dat_sal pair.
 *
 * This is a straight line-by-line port, including the original's control
 * flow quirks (independent, non-exclusive `if`s for the trio tier
 * selection where the LAST matching condition wins on boundary ties; the
 * "if accumulator is empty, overwrite; else append" concatenation that
 * silently swallows leading-empty values in stavkav/Pokazael but not
 * embedded ones) -- these are preserved deliberately so real teachers see
 * the exact numbers the old system would have shown, not a "corrected"
 * version of them.
 *
 * One deliberate deviation: the original's second pass (raschet=true rows
 * -- a stored arithmetic formula over other stavka's own results,
 * evaluated via VBScript's Eval in the original) references a field name
 * ("idstv") that does not exist on wv_stvprep's real column set (it's
 * "idstav" everywhere else in this function) -- calling FieldByName on it
 * would throw, which -- since that inner loop isn't itself wrapped in a
 * try/except, unlike every sibling query in this function -- would abort
 * the *entire* getdatashow0722 call (not just this one prepod/club),
 * leaving get_act_sal to dereference a nil result and fail the whole
 * request. There is exactly one real formula definition in production
 * data (stavka.id=24, "6+7+8+9+10+11+12"), confirmed via a direct query
 * against 1cdbgdsweek1c.mdb, but zero real wv_stvprep rows currently
 * reference it (raschet=true has 0 rows there today) -- so there is no
 * real "correct" output to replicate for this branch yet, only a latent
 * crash. Rather than reproduce a crash-prone dead path, this port detects
 * raschet=true rows if they ever appear and logs a warning instead of
 * evaluating them, so a future assignment of that stavka becomes visible
 * (silent-zero, not silent-crash) rather than breaking the endpoint.
 * ArithmeticExpressionEvaluator exists and is tested for exactly this
 * formula shape if this ever needs finishing.
 */
final class SalaryActService
{
    /**
     * addjo's grouping table (SrvMetod.pas get_act_sal): category name =>
     * list of pipe-string POSITIONS from the original's stlall rows. Each
     * position is (stavka.id + 3) -- position 4 is stv[0] = stavka id 1,
     * so id = position - 3. Kept as the original's own position numbers
     * (matching its comments listing the underlying stavka ids) rather
     * than pre-subtracting, to make this table checkable against the
     * original source line-by-line.
     *
     * @var array<string, list<int>>
     */
    private const GROUPS = [
        'asist' => [9],
        'individ' => [10, 11, 37],
        'group' => [12],
        'zumba' => [13, 14],
        'minigroup' => [17],
        'onlain' => [18],
        'gonorar' => [22],
        'region' => [26, 33, 34, 35],
        'other' => [4, 6, 7, 19, 20, 21, 25, 31, 32],
    ];

    public function __construct(
        private readonly Db $salaryDb,
        private readonly Logger $log,
    ) {
    }

    public function getActSal(?array $filter): string
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

            $date = $this->resolveDate($filter, 'date');
            if ($date === false) {
                return 'ERROR: invalid date';
            }

            $blocks = $this->computeShow0722($phone, $date);

            $weekStart = $this->mondayOfWeek($date);
            $weekEnd = $weekStart->modify('+6 days');

            $result = [];
            foreach ($blocks as $block) {
                $jo = [
                    'prepod' => [
                        'Phone' => $block['phone'],
                        'Club' => $block['club'],
                        'summa' => (string) $block['sumper'],
                    ],
                    'period' => [
                        'begin' => $weekStart->format('d.m.Y'),
                        'end' => $weekEnd->format('d.m.Y'),
                    ],
                ];
                foreach (self::GROUPS as $name => $positions) {
                    $jo[$name] = $this->buildCategory($block['stv'], $block['pok'], $block['sumsp'], $positions);
                }
                $result[] = $jo;
            }

            $json = json_encode($result, JSON_UNESCAPED_UNICODE);
            return $json === false ? 'Error: failed to encode result' : $json;
        } catch (\Throwable $e) {
            $this->log->log('ERROR_getactsal', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * @param array<int, string> $stv
     * @param array<int, string> $pok
     * @param array<int, string> $sumsp
     * @param list<int> $positions
     * @return array{stavkav: string, Pokazael: string, summa: string}
     */
    private function buildCategory(array $stv, array $pok, array $sumsp, array $positions): array
    {
        $sStv = '';
        $sPok = '';
        $sSumm = '';
        foreach ($positions as $position) {
            $k = $position - 3;
            $stvVal = $stv[$k] ?? '';
            $pokVal = $pok[$k] ?? '';
            $summVal = $sumsp[$k] ?? '';

            // Mirrors addjo exactly, quirks included: because both
            // branches check "is the accumulator still empty" rather
            // than "is the *new* value empty", a leading empty value is
            // silently skipped (the accumulator just gets overwritten by
            // the next value while it's still ''), but an empty value
            // appearing *after* the accumulator is already non-empty
            // still gets joined with "; ", producing a visible stray
            // separator -- matches the original's real output byte for
            // byte, not a "cleaned up" join.
            $sStv = $sStv === '' ? $stvVal : $sStv . '; ' . $stvVal;
            $sPok = $sPok === '' ? $pokVal : $sPok . '; ' . $pokVal;
            if ($sSumm === '') {
                $sSumm = $summVal;
            } elseif ($summVal !== '') {
                // sumsp can legitimately hold the literal labels
                // 'textdoit'/'textnotdoit' (plan 0/1/2 branches) instead
                // of a number -- the original does StrToFloat(accumulator)
                // unconditionally here, which throws (and fails the whole
                // request) the moment a plan-based label collides with a
                // real number in the same multi-id category (individ,
                // zumba, region, other). Deliberately not reproducing that
                // crash: prefer whichever side is numeric instead.
                if (is_numeric($sSumm) && is_numeric($summVal)) {
                    $sSumm = (string) ((int) $sSumm + (int) $summVal);
                } elseif (is_numeric($summVal)) {
                    $sSumm = $summVal;
                }
            }
        }

        return ['stavkav' => $sStv, 'Pokazael' => $sPok, 'summa' => $sSumm];
    }

    /**
     * @return list<array{phone: string, club: string, sumper: int, stv: array<int,string>, pok: array<int,string>, sumsp: array<int,string>}>
     */
    private function computeShow0722(string $phone, \DateTimeImmutable $date): array
    {
        $maxRows = $this->salaryDb->query('SELECT MAX(id) AS m FROM stavka');
        $maxStavkaId = (int) ($maxRows[0]['m'] ?? 0);
        if ($maxStavkaId <= 0) {
            return [];
        }

        $weekStart = $this->mondayOfWeek($date);
        $weekEnd = $weekStart->modify('+6 days');
        $d1 = $weekStart->format('Y-m-d');
        $d2 = $weekEnd->format('Y-m-d');
        $rangeStart = $d1 . ' 00:00:00';
        $rangeEnd = $d2 . ' 23:59:59';

        $prepodRows = $this->salaryDb->query(
            'SELECT prepod_id, phone, nameclb FROM wv_prepod WHERE phone = ?',
            [$phone]
        );

        $blocks = [];
        foreach ($prepodRows as $prepodRow) {
            $idprep = (int) $prepodRow['prepod_id'];

            $pv = $this->plandoit0722($idprep, 0, $rangeStart, $rangeEnd);
            $pz1 = $this->plandoit0722($idprep, 1, $rangeStart, $rangeEnd);
            $pz2 = $this->plandoit0722($idprep, 2, $rangeStart, $rangeEnd);

            $pzsRows = $this->salaryDb->query(
                'SELECT SUM(pok) AS s FROM wv_datain WHERE idprep = ? AND dataindo >= ? AND dataindo <= ? AND sumplan = 1',
                [$idprep, $rangeStart, $rangeEnd]
            );
            $pzs = isset($pzsRows[0]['s']) && $pzsRows[0]['s'] !== null ? (int) $pzsRows[0]['s'] : 0;

            /** @var array<int, string> $stv */
            $stv = array_fill(1, $maxStavkaId, '');
            /** @var array<int, string> $pok */
            $pok = array_fill(1, $maxStavkaId, '');
            /** @var array<int, string> $sumsp */
            $sumsp = array_fill(1, $maxStavkaId, '');

            $groupRows = $this->salaryDb->query(
                'SELECT idstv, SUM(pok) AS spok FROM wv_datain WHERE idprep = ? AND dataindo >= ? AND dataindo <= ? GROUP BY idstv',
                [$idprep, $rangeStart, $rangeEnd]
            );
            foreach ($groupRows as $groupRow) {
                $k = (int) $groupRow['idstv'];
                if ($k >= 1 && $k <= $maxStavkaId && $groupRow['spok'] !== null) {
                    $pok[$k] = self::formatNumber((float) $groupRow['spok']);
                }
            }

            $firstPassRows = $this->salaryDb->query(
                'SELECT idstav, minpok, midpok, maxpok, mnojstv, trio, plan FROM wv_stvprep '
                . 'WHERE idprep = ? AND datado >= ? AND datado <= ? AND raschet = 0 ORDER BY plan DESC',
                [$idprep, $rangeStart, $rangeEnd]
            );
            foreach ($firstPassRows as $row) {
                $j = (int) $row['idstav'];
                if ($j < 1 || $j > $maxStavkaId) {
                    continue; // defensive only -- idstav is a FK to stavka.id, which bounds maxStavkaId.
                }

                $minpokStr = $row['minpok'] === null ? '' : (string) (int) $row['minpok'];
                $midpokStr = $row['midpok'] === null ? '' : (string) (int) $row['midpok'];
                $maxpokStr = $row['maxpok'] === null ? '' : (string) (int) $row['maxpok'];
                $mnojstv = (string) ($row['mnojstv'] ?? '');
                $trio = (bool) $row['trio'];
                $planStr = $row['plan'] === null ? '' : (string) (int) $row['plan'];
                $pokazForJ = $pok[$j];

                if ($planStr === '0') {
                    $stv[$j] = (string) $pv;
                    $sumsp[$j] = $pv !== 0 ? 'textnotdoit' : 'textdoit';
                } elseif ($planStr === '1') {
                    $stv[$j] = (string) $pz1;
                    $pok[$j] = (string) $pzs;
                    $sumsp[$j] = $pz1 > $pzs ? 'textnotdoit' : 'textdoit';
                } elseif ($planStr === '2') {
                    $stv[$j] = (string) $pz2;
                    $pok[$j] = (string) $pzs;
                    $sumsp[$j] = $pz2 > $pzs ? 'textnotdoit' : 'textdoit';
                } elseif ($minpokStr === $maxpokStr || $minpokStr === '0' || $maxpokStr === '0') {
                    if ($minpokStr === '0') {
                        $stv[$j] = str_contains($mnojstv, '%') ? $maxpokStr . '%' : $maxpokStr;
                        $sumsp[$j] = self::stvumpokz($maxpokStr, $pokazForJ, $mnojstv);
                    } else {
                        $stv[$j] = str_contains($mnojstv, '%') ? $minpokStr . '%' : $minpokStr;
                        $sumsp[$j] = self::stvumpokz($minpokStr, $pokazForJ, $mnojstv);
                    }
                } elseif ($trio) {
                    $stv[$j] = $minpokStr . '/' . $midpokStr . '/' . $maxpokStr;
                    if ($pv !== 0) {
                        $sumsp[$j] = self::stvumpokz($minpokStr, $pokazForJ, $mnojstv);
                    } else {
                        // Independent ifs, not elseif, matching the original exactly: on a
                        // boundary tie (pz1==pzs or pz2==pzs) more than one fires and the
                        // LAST one wins, overwriting the earlier assignment.
                        if ($pz1 >= $pzs) {
                            $sumsp[$j] = self::stvumpokz($minpokStr, $pokazForJ, $mnojstv);
                        }
                        if ($pz1 <= $pzs && $pz2 >= $pzs) {
                            $sumsp[$j] = self::stvumpokz($midpokStr, $pokazForJ, $mnojstv);
                        }
                        if ($pz2 <= $pzs) {
                            $sumsp[$j] = self::stvumpokz($maxpokStr, $pokazForJ, $mnojstv);
                        }
                    }
                } else {
                    $stv[$j] = $minpokStr . '/' . $maxpokStr;
                    if ($pv !== 0) {
                        $sumsp[$j] = self::stvumpokz($minpokStr, $pokazForJ, $mnojstv);
                    } else {
                        $sumsp[$j] = $pz1 > $pzs
                            ? self::stvumpokz($minpokStr, $pokazForJ, $mnojstv)
                            : self::stvumpokz($maxpokStr, $pokazForJ, $mnojstv);
                    }
                }
            }

            $raschetCountRows = $this->salaryDb->query(
                'SELECT COUNT(*) AS c FROM wv_stvprep WHERE idprep = ? AND datado >= ? AND datado <= ? AND raschet = 1',
                [$idprep, $rangeStart, $rangeEnd]
            );
            if ((int) ($raschetCountRows[0]['c'] ?? 0) > 0) {
                $this->log->log(
                    'WARN_getactsal_raschet',
                    "idprep={$idprep}: raschet=1 row(s) present for the week of {$d1} but not evaluated -- see SalaryActService's class docblock"
                );
            }

            $sumper = 0;
            for ($k = 1; $k <= $maxStavkaId; $k++) {
                $v = $sumsp[$k];
                if ($v !== '' && $v !== 'textdoit' && $v !== 'textnotdoit') {
                    $sumper += (int) $v;
                }
            }

            $constRows = $this->salaryDb->query(
                "SELECT minpok FROM wv_stvprep WHERE idprep = ? AND datado >= ? AND datado <= ? AND mnojstv = 'const' LIMIT 1",
                [$idprep, $rangeStart, $rangeEnd]
            );
            if (count($constRows) > 0) {
                $minpokConst = (int) ($constRows[0]['minpok'] ?? 0);
                $sumper -= $minpokConst;
                if ($minpokConst >= $sumper) {
                    $sumper = $minpokConst;
                }
            }

            $blocks[] = [
                'phone' => (string) $prepodRow['phone'],
                'club' => (string) ($prepodRow['nameclb'] ?? ''),
                'sumper' => $sumper,
                'stv' => $stv,
                'pok' => $pok,
                'sumsp' => $sumsp,
            ];
        }

        return $blocks;
    }

    private function plandoit0722(int $idprep, int $plan, string $rangeStart, string $rangeEnd): int
    {
        $rows = $this->salaryDb->query(
            'SELECT SUM(minpok) AS s FROM wv_stvprep WHERE idprep = ? AND plan = ? AND datado >= ? AND datado <= ?',
            [$idprep, $plan, $rangeStart, $rangeEnd]
        );
        $value = $rows[0]['s'] ?? null;
        return $value === null ? 0 : (int) $value;
    }

    /** Ports stvumpokz (SrvMetod.pas): applies stavka.mnojstv's multiplier convention to (stav, pokaz). */
    private static function stvumpokz(string $stav, string $pokaz, string $mnojstv): string
    {
        if ($stav === '') {
            $stav = '0';
        }
        if ($pokaz === '') {
            $pokaz = '0';
        }
        $stavF = (float) str_replace(',', '.', $stav);
        $pokazF = (float) str_replace(',', '.', $pokaz);

        if (str_contains($mnojstv, '%')) {
            return (string) self::truncMul($stavF, $pokazF / 100);
        }
        if (str_contains($mnojstv, 'const')) {
            return $stav;
        }
        if (str_contains($mnojstv, '*')) {
            $multiplierStr = substr($mnojstv, strpos($mnojstv, '*') + 1);
            $multiplierF = (float) str_replace(',', '.', $multiplierStr);
            return (string) self::truncMul($stavF, $multiplierF);
        }

        return (string) self::truncMul($stavF, $pokazF);
    }

    /** Truncates toward zero like Pascal's Trunc(), rounding first to absorb float noise from the multiplication. */
    private static function truncMul(float $a, float $b): int
    {
        return (int) round($a * $b, 6);
    }

    private static function formatNumber(float $value): string
    {
        if ($value === (float) (int) $value) {
            return (string) (int) $value;
        }
        $formatted = rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
        return $formatted;
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
