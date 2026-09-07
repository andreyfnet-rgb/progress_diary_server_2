<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Minimal read-only parser for dBase III+ .dbf files (no memo/.dbt
 * support needed -- none of the fields this app reads are Memo type).
 *
 * Built to replace the legacy server's ODBC dBase driver
 * (Provider=MSDASQL.1 ... FIL=dBase 5.0, see addschedule_dbf in the old
 * SrvMetod.pas), which only exists on Windows -- the target Linux hosting
 * has no equivalent, so this reads the binary format directly instead.
 * The dBase binary layout is simple and stable (unchanged since the
 * 1980s), so a small dependency-free parser is the more portable choice
 * over trying to find/require an external tool on shared hosting.
 *
 * Confirmed against a real club export file (dp179.dbf): text fields are
 * CP866 (DOS Cyrillic) -- e.g. a SERVICENAM value decodes to
 * "Ассистирования", matching the legacy sqldbf.sql filter
 * "SERVICENAM not like '%ссист%'".
 */
final class DbfReader
{
    /** @var list<array{name: string, type: string, length: int, decimals: int}> */
    private array $fields;
    private int $recordCount;
    private int $headerLength;
    private int $recordLength;
    private $handle;

    public function __construct(private readonly string $path)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("could not open DBF file: {$path}");
        }
        $this->handle = $handle;

        $header = fread($this->handle, 32);
        if ($header === false || strlen($header) < 32) {
            throw new \RuntimeException("not a valid DBF file (short header): {$path}");
        }

        $unpacked = unpack('Cversion/Cyear/Cmonth/Cday/Vrecords/vheaderlen/vrecordlen', $header);
        $this->recordCount = $unpacked['records'];
        $this->headerLength = $unpacked['headerlen'];
        $this->recordLength = $unpacked['recordlen'];

        $this->fields = [];
        while (true) {
            $descriptor = fread($this->handle, 32);
            if ($descriptor === false || strlen($descriptor) < 32) {
                break;
            }
            if ($descriptor[0] === "\x0D") {
                break; // field descriptor array terminator
            }

            $name = rtrim(substr($descriptor, 0, 11), "\x00");
            $type = $descriptor[11];
            $length = ord($descriptor[16]);
            $decimals = ord($descriptor[17]);
            $this->fields[] = ['name' => $name, 'type' => $type, 'length' => $length, 'decimals' => $decimals];
        }

        fseek($this->handle, $this->headerLength);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(static fn(array $f): string => $f['name'], $this->fields);
    }

    public function recordCount(): int
    {
        return $this->recordCount;
    }

    /**
     * Yields one associative array per non-deleted record, in file order.
     * Text (type 'C') fields are converted from CP866 to UTF-8 and
     * right-trimmed (dBase pads with spaces); numeric ('N') fields are
     * returned as trimmed numeric strings; date ('D') fields as "Y-m-d"
     * (or '' if blank); logical ('L') fields as bool.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function records(): \Generator
    {
        fseek($this->handle, $this->headerLength);

        for ($i = 0; $i < $this->recordCount; $i++) {
            $raw = fread($this->handle, $this->recordLength);
            if ($raw === false || strlen($raw) < $this->recordLength) {
                break;
            }

            $deleted = $raw[0] === '*';
            if ($deleted) {
                continue;
            }

            $record = [];
            $offset = 1; // skip the deletion flag byte
            foreach ($this->fields as $field) {
                $raw_value = substr($raw, $offset, $field['length']);
                $offset += $field['length'];
                $record[$field['name']] = $this->decodeField($field, $raw_value);
            }

            yield $record;
        }
    }

    private function decodeField(array $field, string $rawValue): string|bool
    {
        switch ($field['type']) {
            case 'L':
                $flag = strtoupper(trim($rawValue));
                return $flag === 'T' || $flag === 'Y';
            case 'D':
                $trimmed = trim($rawValue);
                if ($trimmed === '' || strlen($trimmed) < 8) {
                    return '';
                }
                return substr($trimmed, 0, 4) . '-' . substr($trimmed, 4, 2) . '-' . substr($trimmed, 6, 2);
            case 'N':
            case 'F':
                return trim($rawValue);
            case 'C':
            default:
                $text = rtrim($rawValue);
                $converted = @iconv('CP866', 'UTF-8//IGNORE', $text);
                return $converted === false ? $text : $converted;
        }
    }
}
