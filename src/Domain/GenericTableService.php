<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\DelphiValueFormatter;
use Gdpd\Data\Db;
use Gdpd\Data\RowFormatter;
use Gdpd\Data\SchemaGuard;
use Gdpd\Infrastructure\Logger;

/**
 * Ports the legacy generic get_dattab/put_dattab endpoints (SrvMetod.pas)
 * -- read/write arbitrary tables by name, driven entirely by the request
 * JSON. Confirmed via the real dp.galladance.com code as the single most
 * used pair of endpoints (every lookup and most writes go through these,
 * not the dedicated per-table routes) and, in the original, the main
 * SQL-injection surface: the table name and (for the non-"where" filter
 * shape) column names came straight from the request and were
 * concatenated into SQL text.
 *
 * Preserved exactly from the legacy behaviour:
 * - A filter object whose first key is literally "where" is treated as a
 *   raw SQL WHERE-clause fragment and appended as-is. This is a
 *   deliberate, pre-existing feature of the API (dp.galladance.com uses it
 *   constantly, e.g. {"where": "(cln_id=123)"}), not an oversight -- it is
 *   NOT parameterizable since it's a full boolean expression rather than a
 *   value, so it is left as a documented, API-key-gated trust boundary
 *   rather than silently removed.
 * - Otherwise every {column: value} pair becomes a "column = value" AND
 *   condition, except an empty string value which becomes "column IS
 *   NULL" (matching the original's exact semantics for empty values).
 * - tab = "DP_blok1_less" gets "order by pb1_id" appended.
 * - put_dattab: a row object whose first key is "id" is an UPDATE (looked
 *   up via sys_tab.tab_idkey for the table's key column), anything else is
 *   an INSERT.
 * - put_dattab ALWAYS returns "ok" once past the initial validation, even
 *   if individual rows in the batch failed -- exactly like the original,
 *   which logs but swallows per-row exceptions inside the loop and then
 *   unconditionally sets Result := 'ok' after it. This looks like a bug
 *   but is the existing contract; callers must not assume "ok" means
 *   every row succeeded.
 *
 * Changed from the legacy behaviour (SQL-injection hardening + real
 * case-insensitive routing on MySQL, no observable difference for any
 * request shape the API already serves): table/column names are resolved
 * and validated against the real schema (see SchemaGuard), and every
 * value is bound as a PDO parameter instead of being concatenated into
 * the SQL text.
 */
final class GenericTableService
{
    public function __construct(
        private readonly Db $db,
        private readonly SchemaGuard $schema,
        private readonly Logger $log,
    ) {
    }

    /** @param array<string, mixed>|null $filter */
    public function getDatTab(string $table, ?array $filter): string
    {
        try {
            $resolvedTable = $this->schema->resolveTable($table);
            if ($resolvedTable === null) {
                return "Error: unknown table '{$table}'";
            }

            $sql = "SELECT * FROM `{$resolvedTable}`";
            $params = [];

            if ($filter !== null && count($filter) > 0) {
                $firstKey = array_key_first($filter);
                if ($firstKey === 'where') {
                    $sql .= ' WHERE ' . JsonValue::toString($filter['where']);
                } else {
                    $conditions = [];
                    foreach ($filter as $column => $valueNode) {
                        $resolvedColumn = $this->schema->resolveColumn($resolvedTable, (string) $column);
                        if ($resolvedColumn === null) {
                            return "Error: unknown column '{$column}'";
                        }

                        $value = JsonValue::toString($valueNode);
                        if ($value === '') {
                            $conditions[] = "`{$resolvedColumn}` IS NULL";
                        } else {
                            $conditions[] = "`{$resolvedColumn}` = ?";
                            $params[] = $value;
                        }
                    }

                    $sql .= ' WHERE ' . implode(' AND ', $conditions);
                }
            }

            if (strcasecmp($resolvedTable, 'DP_blok1_less') === 0) {
                $sql .= ' ORDER BY pb1_id';
            }

            [$rows, $columnNames] = $this->db->queryWithColumns($sql, $params);
            return $this->encodeRows($resolvedTable, $rows, $columnNames);
        } catch (\Throwable $e) {
            $this->log->log('ERROR_getdattab', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /** @param list<array<string, mixed>> $rows */
    public function putDatTab(string $table, array $rows): string
    {
        try {
            $resolvedTable = $this->schema->resolveTable($table);
            if ($resolvedTable === null) {
                return "Error: unknown table '{$table}'";
            }

            $keyColumn = null;
            $keyColumnLoaded = false;

            foreach ($rows as $row) {
                try {
                    if (!is_array($row) || count($row) === 0) {
                        continue;
                    }

                    $firstKey = array_key_first($row);
                    if ($firstKey === 'id') {
                        if (!$keyColumnLoaded) {
                            $keyColumn = $this->getKeyColumn($resolvedTable);
                            $keyColumnLoaded = true;
                        }
                        if ($keyColumn === null) {
                            throw new \RuntimeException("no sys_tab entry for table '{$resolvedTable}'");
                        }

                        $setClauses = [];
                        $params = [];
                        $rest = $row;
                        array_shift($rest);
                        foreach ($rest as $column => $valueNode) {
                            $resolvedColumn = $this->schema->resolveColumn($resolvedTable, (string) $column);
                            if ($resolvedColumn === null) {
                                throw new \RuntimeException("unknown column '{$column}'");
                            }
                            $setClauses[] = "`{$resolvedColumn}` = ?";
                            $params[] = $this->bindValue($resolvedTable, $resolvedColumn, $valueNode);
                        }
                        $params[] = JsonValue::toString($row['id']);

                        $sql = "UPDATE `{$resolvedTable}` SET " . implode(', ', $setClauses) . " WHERE `{$keyColumn}` = ?";
                        $affected = $this->db->execute($sql, $params);
                        if ($affected === 0) {
                            $this->log->log('ERROR_putdattab', "update matched no rows: {$resolvedTable}.{$keyColumn} = " . JsonValue::toString($row['id']));
                        }
                    } else {
                        $columns = [];
                        $params = [];
                        foreach ($row as $column => $valueNode) {
                            $resolvedColumn = $this->schema->resolveColumn($resolvedTable, (string) $column);
                            if ($resolvedColumn === null) {
                                throw new \RuntimeException("unknown column '{$column}'");
                            }
                            $columns[] = "`{$resolvedColumn}`";
                            $params[] = $this->bindValue($resolvedTable, $resolvedColumn, $valueNode);
                        }

                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $sql = "INSERT INTO `{$resolvedTable}` (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
                        $this->db->execute($sql, $params);
                    }
                } catch (\Throwable $e) {
                    // Matches the legacy loop exactly: log and move on. The
                    // method still returns "ok" below regardless.
                    $this->log->log('ERROR_putdattab', $e->getMessage());
                }
            }

            return 'ok';
        } catch (\Throwable $e) {
            $this->log->log('ERROR_putdattab', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Converts a request value to what actually gets bound to the SQL
     * parameter: for a column SchemaGuard reports as a date/datetime type,
     * parses the "dd.MM.yyyy[ H:mm:ss]" shape clients actually send (the
     * same shape this API's read side produces) into MySQL's format;
     * everything else passes through JsonValue::toString unchanged.
     */
    private function bindValue(string $resolvedTable, string $resolvedColumn, mixed $valueNode): ?string
    {
        $value = JsonValue::toString($valueNode);
        if ($this->schema->isDateColumn($resolvedTable, $resolvedColumn)) {
            return DelphiValueFormatter::parseDateTimeInput($value);
        }

        return $value;
    }

    private function getKeyColumn(string $resolvedTable): ?string
    {
        $rows = $this->db->query('SELECT tab_idkey FROM sys_tab WHERE tab_name = ?', [$resolvedTable]);
        return $rows[0]['tab_idkey'] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columnNames
     */
    private function encodeRows(string $resolvedTable, array $rows, array $columnNames): string
    {
        return RowFormatter::toJson($this->schema, $resolvedTable, $rows, $columnNames);
    }
}
