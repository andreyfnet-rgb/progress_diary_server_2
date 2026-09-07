<?php

declare(strict_types=1);

namespace Gdpd\Domain\Lookups;

use Gdpd\Data\Db;
use Gdpd\Data\RowFormatter;
use Gdpd\Domain\JsonValue;
use Gdpd\Infrastructure\Logger;

/**
 * Ports the small dedicated GET routes that each mirror one legacy
 * SrvMetod.pas function operating on a single fixed table: get_prep
 * (prepod), get_client (client), get_TypeLess (lesstype), get_figura
 * (figura), get_Lessobj (lessobj), get_lesswrk (lesswrk). Unlike
 * getdattab/putdattab, the table name here is a hard-coded literal, not
 * request input, so there's no need to route through SchemaGuard's
 * whitelist for the table itself (only used for per-column
 * boolean/date-formatting metadata via RowFormatter).
 *
 * Preserved from the legacy behaviour: an empty/absent filter returns all
 * rows (ordered by name where the original did); a filter is expected to
 * carry either "id" or (for prepod/client) a phone field, and anything
 * else is "ERROR: Invalid parameter". The legacy code actually detected
 * these via a substring search over the whole JSON text (`pos('id', ...)
 * <> 0`), which is a latent bug (e.g. would also match a key like
 * "cln_id" it didn't mean to) -- this port uses a proper key-existence
 * check instead, which matches every real request shape observed in the
 * dp.galladance.com code (e.g. `postquery(['id' => ...], 'Prepod')`)
 * without inheriting that bug.
 */
final class LookupService
{
    public function __construct(
        private readonly Db $db,
        private readonly \Gdpd\Data\SchemaGuard $schema,
        private readonly Logger $log,
    ) {
    }

    public function getPrepod(?array $filter): string
    {
        return $this->getFiltered(
            table: 'prepod',
            filter: $filter,
            idColumn: 'prp_id',
            phoneColumn: 'prp_phone',
            baseWhere: 'prp_out = 0',
            orderBy: 'prp_name',
            errorTag: 'ERROR_getprep',
        );
    }

    public function getClient(?array $filter): string
    {
        return $this->getFiltered(
            table: 'Client',
            filter: $filter,
            idColumn: 'cln_id',
            phoneColumn: 'cln_phone',
            baseWhere: null,
            orderBy: 'cln_name',
            errorTag: 'ERROR_getclient',
        );
    }

    public function getLessType(?array $filter): string
    {
        // Table names must match the real CREATE TABLE casing exactly on
        // Linux MySQL (lower_case_table_names=0, the default) -- confirmed
        // the hard way: 'lesstype' here worked fine against this project's
        // Windows dev MySQL (case-insensitive) but 404'd as "table doesn't
        // exist" against the real Linux-hosted production database, where
        // the table is actually named "LessType".
        return $this->getById('LessType', $filter, 'lt_id', 'ERROR_getTypeLess');
    }

    public function getFigura(?array $filter): string
    {
        return $this->getById('figura', $filter, 'fgr_id', 'ERROR_getfigura');
    }

    public function getLessObj(?array $filter): string
    {
        return $this->getById('LessObj', $filter, 'lo_id', 'ERROR_getLessobj');
    }

    public function getLessWrk(?array $filter): string
    {
        return $this->getById('LessWrk', $filter, 'lw_id', 'ERROR_getlesswrk');
    }

    /** id-only lookup: null filter -> all rows, {"id": ...} -> one row, anything else -> error. */
    private function getById(string $table, ?array $filter, string $idColumn, string $errorTag): string
    {
        try {
            if ($filter === null) {
                $sql = "SELECT * FROM `{$table}`";
                $params = [];
            } elseif (array_key_exists('id', $filter)) {
                $sql = "SELECT * FROM `{$table}` WHERE `{$idColumn}` = ?";
                $params = [JsonValue::toString($filter['id'])];
            } else {
                // The legacy code would dereference a nil GetValue('id')
                // here and crash with whatever Access Violation message
                // Delphi produced; no real client sends this shape, so a
                // clean error is used instead of trying to reproduce that.
                return "Error: 'id' is required";
            }

            [$rows, $columnNames] = $this->db->queryWithColumns($sql, $params);
            return RowFormatter::toJson($this->schema, $table, $rows, $columnNames);
        } catch (\Throwable $e) {
            $this->log->log($errorTag, $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /** id or phone lookup, with an optional extra WHERE and ORDER BY, matching get_prep/get_client. */
    private function getFiltered(
        string $table,
        ?array $filter,
        string $idColumn,
        string $phoneColumn,
        ?string $baseWhere,
        string $orderBy,
        string $errorTag,
    ): string {
        try {
            $conditions = $baseWhere !== null ? [$baseWhere] : [];
            $params = [];

            if ($filter === null) {
                // no extra condition
            } elseif (array_key_exists('id', $filter)) {
                $conditions[] = "`{$idColumn}` = ?";
                $params[] = JsonValue::toString($filter['id']);
            } elseif (array_key_exists($phoneColumn, $filter)) {
                $conditions[] = "`{$phoneColumn}` = ?";
                $params[] = JsonValue::toString($filter[$phoneColumn]);
            } else {
                return 'ERROR: Invalid parameter';
            }

            $sql = "SELECT * FROM `{$table}`";
            if (count($conditions) > 0) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $sql .= " ORDER BY `{$orderBy}`";

            [$rows, $columnNames] = $this->db->queryWithColumns($sql, $params);
            return RowFormatter::toJson($this->schema, $table, $rows, $columnNames);
        } catch (\Throwable $e) {
            $this->log->log($errorTag, $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
}
