<?php

declare(strict_types=1);

namespace Gdpd\Domain\Lookups;

use Gdpd\Data\Db;
use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Domain\JsonValue;
use Gdpd\Infrastructure\Logger;

/**
 * Ports get_dance (SrvMetod.pas), which has two genuinely different
 * response shapes depending on the request -- confirmed against the real
 * server's output, not just the source:
 *
 * - With a filter {"id": <dns_id>}: a flat array of
 *   {dns_id, dns_name, dt_name} joining dance+dancetype, ALL values as
 *   strings (same generic-field-iteration shape as every other lookup).
 * - With no filter: dancetype rows, each carrying a nested "dance" array
 *   of {dns_id, dns_name} for that direction -- and inside that nested
 *   array dns_id is a real JSON NUMBER, not a string (the legacy code
 *   builds it with TJSONNumber.Create explicitly, unlike everywhere else).
 *   Confirmed live: `/dance` with no body returns
 *   `"dance":[{"dns_id":1,"dns_name":"Waltz"}, ...]` (unquoted number)
 *   while `/getdattab?tab=dance` returns `"dns_id":"1"` (string) for the
 *   same column. Both shapes must be kept exactly as observed.
 */
final class DanceService
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $log,
    ) {
    }

    public function getDance(?array $filter): string
    {
        try {
            if ($filter !== null && count($filter) > 0) {
                return $this->getSingleDance($filter);
            }

            return $this->getAllGroupedByType();
        } catch (\Throwable $e) {
            $this->log->log('ERROR_gedance', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    private function getSingleDance(array $filter): string
    {
        if (!array_key_exists('id', $filter)) {
            return "Error: 'id' is required";
        }

        $rows = $this->db->query(
            'SELECT d.dns_id, d.dns_name, dt.dt_name
             FROM dancetype dt, dance d
             WHERE d.id_dt = dt.dt_id AND d.dns_id = ?',
            [JsonValue::toString($filter['id'])]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'dns_id' => DelphiValueFormatter::formatValue($row['dns_id'], false, false),
                'dns_name' => DelphiValueFormatter::formatValue($row['dns_name'], false, false),
                'dt_name' => DelphiValueFormatter::formatValue($row['dt_name'], false, false),
            ];
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        return $json === false ? 'Error: failed to encode result' : $json;
    }

    private function getAllGroupedByType(): string
    {
        $types = $this->db->query('SELECT * FROM dancetype ORDER BY dt_name');

        $result = [];
        foreach ($types as $type) {
            $dances = $this->db->query('SELECT * FROM dance WHERE id_dt = ?', [$type['dt_id']]);
            $result[] = [
                'dt_id' => DelphiValueFormatter::formatValue($type['dt_id'], false, false),
                'dt_name' => DelphiValueFormatter::formatValue($type['dt_name'], false, false),
                'dance' => array_map(
                    static fn(array $d): array => [
                        'dns_id' => (int) $d['dns_id'],
                        'dns_name' => DelphiValueFormatter::formatValue($d['dns_name'], false, false),
                    ],
                    $dances
                ),
            ];
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        return $json === false ? 'Error: failed to encode result' : $json;
    }
}
