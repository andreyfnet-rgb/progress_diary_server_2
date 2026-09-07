<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Data\Db;
use Gdpd\Infrastructure\Logger;

/**
 * Ports get_lvlstat (SrvMetod.pas). No confirmed live caller was found in
 * either dp.galladance.com or galladance.com's local/api/dp -- ported for
 * completeness/API-surface parity, not because a consumer is waiting on
 * it.
 *
 * Produces an unusual shape on purpose, matching the original exactly: an
 * array with one object per dance, each keyed by the dance's id (as a
 * string) whose value is another object keyed by level id (also a
 * string) mapping to a completion percentage -- as a plain number string
 * with full precision (e.g. "33.33333333333333", not rounded, no '%'
 * sign, using Delphi's FloatToStr rather than get_procend's
 * FloatToStrF(..., ffFixed, 5, 2) -- these two endpoints format
 * percentages completely differently, confirmed against the source, not
 * assumed for consistency).
 */
final class LvlStatService
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $log,
    ) {
    }

    public function getLvlStat(?array $filter): string
    {
        try {
            if ($filter === null) {
                return 'JSON has no tex'; // sic -- matches the original's typo exactly.
            }

            $clnPhone = JsonValue::toString($filter['cln_phone'] ?? '');

            $levels = $this->db->query('SELECT lvl_id FROM lvl');
            $dances = $this->db->query('SELECT dns_id FROM dance');

            $result = [];
            foreach ($dances as $dance) {
                $dnsId = (string) $dance['dns_id'];
                $levelStats = [];
                foreach ($levels as $level) {
                    $lvlId = (string) $level['lvl_id'];
                    $all = (int) $this->db->query(
                        'SELECT COUNT(*) AS n FROM figurles WHERE dns_id = ? AND fgr_level = ? AND (pb2_idcln IS NULL OR cln_phone = ?)',
                        [$dnsId, $lvlId, $clnPhone]
                    )[0]['n'];
                    $done = (int) $this->db->query(
                        'SELECT COUNT(*) AS n FROM figurles WHERE dns_id = ? AND fgr_level = ? AND pb2_datoff IS NOT NULL AND (pb2_idcln IS NULL OR cln_phone = ?)',
                        [$dnsId, $lvlId, $clnPhone]
                    )[0]['n'];

                    $levelStats[$lvlId] = $all === 0 ? '0' : DelphiValueFormatter::formatNumber(($done / $all) * 100);
                }
                $result[] = [$dnsId => $levelStats];
            }

            $json = json_encode($result, JSON_UNESCAPED_UNICODE);
            return $json === false ? 'Error: failed to encode result' : $json;
        } catch (\Throwable $e) {
            $this->log->log('ERROR_lvlstat', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
}
