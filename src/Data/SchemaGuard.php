<?php

declare(strict_types=1);

namespace Gdpd\Data;

/**
 * Validates table/column names coming from API input before they are used
 * in SQL, and resolves them to their canonical (as-declared) casing.
 *
 * Two concerns this addresses at once:
 *
 * 1. SQL injection: the legacy getdattab/putdattab endpoints take the
 *    table name and, for the non-"where" filter shape, column names
 *    straight from the request and concatenate them into SQL text. MySQL
 *    (like Access) has no way to parameterize identifiers, only values, so
 *    this whitelists the identifier against the real schema instead.
 * 2. Case-insensitive routing parity: the real dp.galladance.com code
 *    calls "getdattab?tab=Client", "getdattab?tab=DP_blok2_figur",
 *    "getdattab?tab=Figurles" etc. with inconsistent casing, matching the
 *    legacy Delphi WebBroker's case-insensitive PathInfo matching. MySQL
 *    table name matching IS case-sensitive on typical Linux hosting
 *    (unlike this project's Windows dev box, where it happens to be
 *    case-insensitive by default) -- so table/column names must be
 *    resolved to their real declared casing here rather than relied on to
 *    "just work" the way local testing might suggest.
 */
final class SchemaGuard
{
    private static string $identifierPattern = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @var array<string, array{
     *     name: string,
     *     columns: array<string, array{name: string, isBoolean: bool, isDate: bool}>,
     *     order: list<string>
     * }>
     */
    private array $cache = [];

    public function __construct(private readonly Db $db, private readonly string $databaseName)
    {
    }

    /** Returns the table/view's real declared name, or null if it doesn't exist. */
    public function resolveTable(string $input): ?string
    {
        if (!preg_match(self::$identifierPattern, $input)) {
            return null;
        }

        $key = strtolower($input);
        if (isset($this->cache[$key])) {
            return $this->cache[$key]['name'];
        }

        $rows = $this->db->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = ?',
            [$this->databaseName, $key]
        );
        if (count($rows) === 0) {
            return null;
        }

        $canonical = (string) $rows[0]['TABLE_NAME'];
        $this->loadColumns($canonical, $key);
        return $canonical;
    }

    /** Returns the column's real declared name within $canonicalTable, or null if it doesn't exist there. */
    public function resolveColumn(string $canonicalTable, string $input): ?string
    {
        if (!preg_match(self::$identifierPattern, $input)) {
            return null;
        }

        $tableKey = strtolower($canonicalTable);
        $this->loadColumns($canonicalTable, $tableKey);
        return $this->cache[$tableKey]['columns'][strtolower($input)]['name'] ?? null;
    }

    public function isBooleanColumn(string $canonicalTable, string $canonicalColumn): bool
    {
        $tableKey = strtolower($canonicalTable);
        $this->loadColumns($canonicalTable, $tableKey);
        return $this->cache[$tableKey]['columns'][strtolower($canonicalColumn)]['isBoolean'] ?? false;
    }

    public function isDateColumn(string $canonicalTable, string $canonicalColumn): bool
    {
        $tableKey = strtolower($canonicalTable);
        $this->loadColumns($canonicalTable, $tableKey);
        return $this->cache[$tableKey]['columns'][strtolower($canonicalColumn)]['isDate'] ?? false;
    }

    /** @return list<string> canonical column names in their natural (SELECT *) order */
    public function getColumnOrder(string $canonicalTable): array
    {
        $tableKey = strtolower($canonicalTable);
        $this->loadColumns($canonicalTable, $tableKey);
        return $this->cache[$tableKey]['order'];
    }

    private function loadColumns(string $canonicalTable, string $tableKey): void
    {
        if (isset($this->cache[$tableKey]['columns'])) {
            return;
        }

        $rows = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->databaseName, $canonicalTable]
        );

        $columns = [];
        $order = [];
        foreach ($rows as $row) {
            $name = (string) $row['COLUMN_NAME'];
            $dataType = (string) $row['DATA_TYPE'];
            $columnType = (string) $row['COLUMN_TYPE'];
            $columns[strtolower($name)] = [
                'name' => $name,
                'isBoolean' => $dataType === 'tinyint' && $columnType === 'tinyint(1)',
                'isDate' => in_array($dataType, ['datetime', 'date', 'timestamp'], true),
            ];
            $order[] = $name;
        }

        $this->cache[$tableKey] = [
            'name' => $canonicalTable,
            'columns' => $columns,
            'order' => $order,
        ];
    }
}
