<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Logger;

/**
 * Ports get_calend (SrvMetod.pas) -- confirmed as a live consumer via
 * galladance.com's local/api/dp/index.php ("calend" action), which is how
 * the mobile app shows a client their progress feed.
 *
 * Unlike most lookups, this one hand-picks fields with fixed output names
 * (not generic field iteration) and duplicates a few of them under two
 * different keys -- kept exactly as in the original.
 */
final class CalendService
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $log,
    ) {
    }

    public function getCalend(?array $filter): string
    {
        try {
            if ($filter === null || count($filter) === 0) {
                return 'ERROR: JSON has no text';
            }
            if (!array_key_exists('cln_phone', $filter)) {
                return 'ERROR: Invalid parameter';
            }

            // Table/view names must match the real CREATE-time casing
            // exactly on Linux MySQL (lower_case_table_names=0, the
            // default) -- confirmed against production: the view is
            // actually named "Calend_v2", not "calend_v2".
            $rows = $this->db->query(
                'SELECT * FROM Calend_v2 WHERE cln_phone = ?',
                [JsonValue::toString($filter['cln_phone'])]
            );

            $result = [];
            foreach ($rows as $row) {
                $info = (string) ($row['pb1_aboutless'] ?? '');
                $result[] = [
                    'name' => (string) ($row['dt_name'] ?? ''),
                    'lesstype' => (string) ($row['it_name'] ?? ''),
                    'lessdate' => (string) ($row['pb1_datles'] ?? ''),
                    'prepod' => (string) ($row['prp_name'] ?? ''),
                    'color' => (string) ($row['dt_color'] ?? ''),
                    'info' => $info,
                    'recom' => (string) ($row['pb1_recom'] ?? ''),
                    'prgs_date_create' => (string) ($row['pb1_datles'] ?? ''),
                    'prp_name' => (string) ($row['prp_name'] ?? ''),
                    'it_name' => (string) ($row['it_name'] ?? ''),
                    'dns_name' => (string) ($row['dt_name'] ?? ''),
                    'prp_phone' => (string) ($row['prp_phone'] ?? ''),
                    'prp_link' => (string) ($row['prp_link'] ?? ''),
                ];
            }

            $json = json_encode($result, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return 'Error: failed to encode result';
            }

            // Mirrors a legacy post-processing step: content that already
            // contained a literal backslash-n (2 raw characters, not a
            // real newline) comes out of JSON encoding as a doubled
            // backslash ("\\n", 3 characters); the original un-doubles it
            // back to a single JSON newline escape ("\n") so it renders as
            // a line break for the client instead of literal "\n" text.
            return str_replace(chr(92) . chr(92) . 'n', chr(92) . 'n', $json);
        } catch (\Throwable $e) {
            $this->log->log('ERROR_getTypeLess', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
}
