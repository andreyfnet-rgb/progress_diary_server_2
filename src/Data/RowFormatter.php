<?php

declare(strict_types=1);

namespace Gdpd\Data;

/**
 * Shared "SELECT * -> JSON array of {column: formatted-string}" helper,
 * used by every endpoint that just dumps rows from a single known table
 * the way get_prep/get_client/get_figura/etc. did in the legacy
 * SrvMetod.pas (generic field iteration: `for i := 0 to FieldCount - 1 do
 * jo.AddPair(Fields[i].FieldName, Fields[i].AsString)`).
 */
final class RowFormatter
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columnNames
     * @return list<array<string, string>>
     */
    public static function toArray(SchemaGuard $schema, string $table, array $rows, array $columnNames): array
    {
        $result = [];
        foreach ($rows as $row) {
            $object = [];
            foreach ($columnNames as $column) {
                $isBoolean = $schema->isBooleanColumn($table, $column);
                $isDate = $schema->isDateColumn($table, $column);
                $object[$column] = DelphiValueFormatter::formatValue($row[$column] ?? null, $isBoolean, $isDate);
            }
            $result[] = $object;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columnNames
     */
    public static function toJson(SchemaGuard $schema, string $table, array $rows, array $columnNames): string
    {
        $json = json_encode(self::toArray($schema, $table, $rows, $columnNames), JSON_UNESCAPED_UNICODE);
        return $json === false ? 'Error: failed to encode result' : $json;
    }
}
