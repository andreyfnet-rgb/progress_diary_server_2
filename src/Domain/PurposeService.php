<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Data\Db;
use Gdpd\Data\RowFormatter;
use Gdpd\Data\SchemaGuard;
use Gdpd\Infrastructure\Logger;

/**
 * Ports get_purpose/put_purpose (SrvMetod.pas). Still live on
 * dp.galladance.com today via doit.php's addpp/editpp branches (bound to
 * the dedicated PUT /purpose route by purpose_add.php and
 * ajax/changeDate.php) -- unlike the /inrecPD write path, this one was NOT
 * superseded by generic putdattab.
 */
final class PurposeService
{
    public function __construct(
        private readonly Db $db,
        private readonly SchemaGuard $schema,
        private readonly Logger $log,
    ) {
    }

    public function getPurpose(?array $filter): string
    {
        try {
            if ($filter === null) {
                $sql = 'SELECT * FROM purpose_all';
                $params = [];
            } elseif (count($filter) === 0) {
                return 'ERROR: JSON has no text';
            } elseif (array_key_exists('id_cln', $filter)) {
                $sql = 'SELECT * FROM purpose_all WHERE cln_id = ?';
                $params = [JsonValue::toString($filter['id_cln'])];
            } elseif (array_key_exists('cln_phone', $filter)) {
                $sql = 'SELECT * FROM purpose_all WHERE cln_phone = ?';
                $params = [JsonValue::toString($filter['cln_phone'])];
            } else {
                return 'ERROR: Invalid parameter';
            }

            [$rows, $columnNames] = $this->db->queryWithColumns($sql, $params);
            return RowFormatter::toJson($this->schema, 'purpose_all', $rows, $columnNames);
        } catch (\Throwable $e) {
            $this->log->log('ERROR_getpurpose', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Sparse insert/update on the `purpose` table: id_rec = "0" inserts a
     * new row, anything else updates the row with that clpp_id. Only
     * fields that are both a real purpose column AND present in the
     * request body are written -- exactly like the original, which
     * iterates the table's own field list and skips any that aren't in
     * the request JSON (so extra keys like id_rec itself are naturally
     * ignored without special-casing them).
     *
     * Note the legacy message here is "JSON has no text" with no "ERROR:"
     * prefix -- unlike get_purpose's "ERROR: JSON has no text". Kept
     * exactly, since it also affects the dispatcher's Content-Type choice
     * (only messages containing the literal "Error: " suppress the JSON
     * content type).
     */
    public function putPurpose(array $body): string
    {
        try {
            if (count($body) === 0) {
                return 'JSON has no text';
            }
            if (!array_key_exists('id_rec', $body)) {
                return "Error: 'id_rec' is required";
            }

            $idRec = JsonValue::toString($body['id_rec']);
            $columns = $this->schema->getColumnOrder('purpose');

            $setPairs = [];
            foreach ($columns as $column) {
                if (array_key_exists($column, $body)) {
                    $value = JsonValue::toString($body[$column]);
                    if ($this->schema->isDateColumn('purpose', $column)) {
                        $value = DelphiValueFormatter::parseDateTimeInput($value);
                    }
                    $setPairs[$column] = $value;
                }
            }

            if ($idRec === '0') {
                $cols = array_keys($setPairs);
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO purpose (' . implode(', ', $cols) . ") VALUES ({$placeholders})";
                $this->db->execute($sql, array_values($setPairs));
            } else {
                $sets = [];
                $params = [];
                foreach ($setPairs as $column => $value) {
                    $sets[] = "{$column} = ?";
                    $params[] = $value;
                }
                $params[] = $idRec;
                $sql = 'UPDATE purpose SET ' . implode(', ', $sets) . ' WHERE clpp_id = ?';
                $this->db->execute($sql, $params);
            }

            return 'ok';
        } catch (\Throwable $e) {
            $this->log->log('ERROR_putpurpose', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
}
