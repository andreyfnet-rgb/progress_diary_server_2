<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Logger;

/**
 * Ports get_procend (SrvMetod.pas) -- confirmed as a live consumer via
 * galladance.com's local/api/dp/index.php ("procend" action): the
 * percentage of a dance direction/level's figures a client has completed.
 *
 * Filters (all optional except cln_phone, which is required whenever dt_id
 * is present -- which is every real request seen) narrow down rows in the
 * `wv_figur` view (all figures matching dt_id/dns_id/frg_level) and the
 * `figurles` view (same filter plus the client's completion records, and
 * optionally "completed only" via on_end) -- the response is
 * completed-count / total-count as a percentage.
 *
 * One behaviour kept exactly because it's observable, not because it's
 * good: the exception handler here returns the raw exception message with
 * no "Error: " prefix (every other endpoint in this codebase uses "Error:
 * " + message) -- confirmed against the original SrvMetod.pas source,
 * where get_procend's `except` block is the one place that does
 * `Result := E.Message` instead of `Result := 'Error: ' + E.Message`. This
 * matters because the dispatcher's Content-Type choice is driven by
 * whether the response contains the literal substring "Error: ".
 */
final class ProcEndService
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $log,
    ) {
    }

    public function getProcEnd(?array $filter): string
    {
        $filter ??= [];

        try {
            $conditions = [];
            $params = [];
            // Deliberately kept as a mismatched pair: the legacy Delphi
            // code reads the request key "frg_level" but the real column
            // (both here and in the figura table) is "fgr_level". Real
            // clients -- galladance.com's local/api/dp/index.php -- send
            // the correctly-spelled "fgr_level", which the legacy key
            // check never matches, so this filter has silently never
            // actually applied in production. Fixing the key name would
            // start applying a level filter that has never been active,
            // changing the percentage real users see; the SQL column name
            // is still the correct "fgr_level" so that IF this branch
            // were ever reached (e.g. a future caller using the legacy
            // key on purpose) it wouldn't just throw a SQL error.
            $requestKeyToColumn = ['dt_id' => 'dt_id', 'dns_id' => 'dns_id', 'frg_level' => 'fgr_level'];
            foreach ($requestKeyToColumn as $requestKey => $column) {
                if (array_key_exists($requestKey, $filter) && JsonValue::toString($filter[$requestKey]) !== '') {
                    $conditions[] = "{$column} = ?";
                    $params[] = JsonValue::toString($filter[$requestKey]);
                }
            }

            $totalSql = 'SELECT COUNT(*) AS n FROM wv_figur';
            if (count($conditions) > 0) {
                $totalSql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $total = (int) $this->db->query($totalSql, $params)[0]['n'];

            if (!array_key_exists('dt_id', $filter)) {
                return 'Error:не задан телефон';
            }
            $phone = JsonValue::toString($filter['cln_phone'] ?? '');
            if ($phone === '') {
                return 'Error:не задан телефон';
            }
            $conditions[] = 'cln_phone = ?';
            $params[] = $phone;

            if (array_key_exists('on_end', $filter) && JsonValue::toString($filter['on_end']) !== '') {
                $conditions[] = 'pb2_datoff IS NOT NULL';
            }

            // "Figurles" (capital F), matching the real CREATE VIEW casing
            // -- see the case-sensitivity note in LvlStatService.
            $completedSql = 'SELECT COUNT(*) AS n FROM Figurles WHERE ' . implode(' AND ', $conditions);
            $completed = (int) $this->db->query($completedSql, $params)[0]['n'];

            if ($total === 0) {
                // Matches the original crashing on a division by zero and
                // the outer except returning the raw exception text.
                throw new \DivisionByZeroError('Division by zero');
            }

            $percent = round($completed / $total * 100, 2);
            $json = json_encode([['proc' => number_format($percent, 2, ',', '') . '%']], JSON_UNESCAPED_UNICODE);
            return $json === false ? 'Error: failed to encode result' : $json;
        } catch (\Throwable $e) {
            $this->log->log('ERORR_get_procend', $e->getMessage());
            return $e->getMessage();
        }
    }
}
