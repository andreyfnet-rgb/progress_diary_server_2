<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Data\Db;
use Gdpd\Data\RowFormatter;
use Gdpd\Data\SchemaGuard;
use Gdpd\Infrastructure\Logger;

/**
 * Ports get_inrecPD/put_inrecPD (SrvMetod.pas) -- the ORIGINAL
 * progress-diary read/write path, operating on the `progress` table.
 *
 * Low priority and largely dead: the live dp.galladance.com code
 * (pd.php/ajax/newCreate.php) writes new entries through generic
 * `putdattab?tab=DP_blok1_less` instead (see GenericTableService's
 * docblock), and only reads through `getdattab?tab=get_inrecPD`. The
 * dedicated PUT /inrecPD route is only reachable from old/3.php ->
 * old/doit.php, which the current site doesn't link to.
 *
 * get_inrecPD's read side is even more clearly stale: its SQL filters by
 * columns ("prgs_idprp", "prgs_idcln", "cln_phone", "prp_phone") that
 * don't exist on the `get_inrecPD` view as currently defined (dumped
 * directly from the live Access database -- its real columns are
 * pb1_id/pb1_idcln/pb1_idprp/pb1_date/pb1_aboutless/it_name/pb1_idd/dt_name).
 * This looks like a leftover from before the site's DP_blok1_less
 * migration: the view's column names changed but this function's filters
 * never got updated, and it has no live caller to have surfaced the
 * break. Ported as-is -- including the broken filters -- rather than
 * guessed-and-fixed, since "fixed" behaviour here has never been
 * observed and would be a guess, not a restoration.
 */
final class InRecPdService
{
    public function __construct(
        private readonly Db $db,
        private readonly SchemaGuard $schema,
        private readonly Logger $log,
    ) {
    }

    public function getInRecPd(?array $filter): string
    {
        try {
            if ($filter === null) {
                return 'Error: Invalid parameter';
            }

            $sql = 'SELECT * FROM get_inrecPD';
            $params = [];

            if (array_key_exists('id_cln', $filter) || array_key_exists('id_prp', $filter)) {
                $idCln = JsonValue::toString($filter['id_cln'] ?? '');
                $idPrp = JsonValue::toString($filter['id_prp'] ?? '');
                [$where, $params] = $this->buildIdWhere($idCln, $idPrp, 'prgs_idprp', 'prgs_idcln');
                $sql .= $where;
            } elseif (array_key_exists('cln_phone', $filter) || array_key_exists('prp_phone', $filter)) {
                $clnPhone = JsonValue::toString($filter['cln_phone'] ?? '');
                $prpPhone = JsonValue::toString($filter['prp_phone'] ?? '');
                [$where, $params] = $this->buildPhoneWhere($clnPhone, $prpPhone);
                $sql .= $where;
            } else {
                return 'ERROR: Invalid parameter';
            }

            $sql .= ' ORDER BY pb1_id';

            [$rows, $columnNames] = $this->db->queryWithColumns($sql, $params);
            return RowFormatter::toJson($this->schema, 'get_inrecPD', $rows, $columnNames);
        } catch (\Throwable $e) {
            $this->log->log('ERROR_inrecPD_get', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /** @return array{0: string, 1: list<string>} */
    private function buildIdWhere(string $idCln, string $idPrp, string $prpColumn, string $clnColumn): array
    {
        if ($idCln === '0') {
            return [" WHERE {$prpColumn} = ?", [$idPrp]];
        }
        if ($idPrp === '0') {
            return [" WHERE {$clnColumn} = ?", [$idCln]];
        }
        return [" WHERE {$prpColumn} = ? AND {$clnColumn} = ?", [$idPrp, $idCln]];
    }

    /** @return array{0: string, 1: list<string>} */
    private function buildPhoneWhere(string $clnPhone, string $prpPhone): array
    {
        if ($clnPhone === '0') {
            return [' WHERE prp_phone = ?', [$prpPhone]];
        }
        if ($prpPhone === '0') {
            return [' WHERE cln_phone = ?', [$clnPhone]];
        }
        return [' WHERE prp_phone = ? AND cln_phone = ?', [$prpPhone, $clnPhone]];
    }

    /**
     * Sparse insert/update on `progress`, same pattern as
     * PurposeService::putPurpose: id_rec = "0" inserts, otherwise updates
     * by prgs_id; only real progress columns present in the body are
     * written. prgs_date_create/prgs_date_cheng are always server-managed
     * (set to "now"), matching the original excluding them from the
     * request-driven field loop.
     */
    public function putInRecPd(array $body): string
    {
        try {
            if (count($body) === 0) {
                return 'JSON has no text';
            }
            if (!array_key_exists('id_rec', $body)) {
                return "Error: 'id_rec' is required";
            }

            $idRec = JsonValue::toString($body['id_rec']);
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $columns = $this->schema->getColumnOrder('progress');

            $setPairs = [];
            foreach ($columns as $column) {
                if (in_array($column, ['prgs_date_create', 'prgs_date_cheng'], true)) {
                    continue;
                }
                if (array_key_exists($column, $body)) {
                    $value = JsonValue::toString($body[$column]);
                    if ($this->schema->isDateColumn('progress', $column)) {
                        $value = DelphiValueFormatter::parseDateTimeInput($value);
                    }
                    $setPairs[$column] = $value;
                }
            }

            if ($idRec === '0') {
                $setPairs['prgs_date_create'] = $now;
                $setPairs['prgs_date_cheng'] = $now;
                $cols = array_keys($setPairs);
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO progress (' . implode(', ', $cols) . ") VALUES ({$placeholders})";
                $this->db->execute($sql, array_values($setPairs));
            } else {
                $setPairs['prgs_date_cheng'] = $now;
                $sets = [];
                $params = [];
                foreach ($setPairs as $column => $value) {
                    $sets[] = "{$column} = ?";
                    $params[] = $value;
                }
                $params[] = $idRec;
                $sql = 'UPDATE progress SET ' . implode(', ', $sets) . ' WHERE prgs_id = ?';
                $this->db->execute($sql, $params);
            }

            return 'ok';
        } catch (\Throwable $e) {
            $this->log->log('ERROR_inrecPD_put', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
}
