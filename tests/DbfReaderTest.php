<?php

declare(strict_types=1);

use Gdpd\Infrastructure\DbfReader;

/**
 * Builds a small synthetic dBase III file byte-for-byte (rather than
 * shipping a real club export, which would carry real client/teacher
 * names and phone numbers) covering the field types and encoding this
 * app actually relies on: C (CP866 text), N (numeric), D (date), L
 * (logical), plus one soft-deleted record that must be skipped.
 */
function buildSyntheticDbf(): string
{
    $fields = [
        ['name' => 'NAME', 'type' => 'C', 'length' => 20, 'decimals' => 0],
        ['name' => 'AMOUNT', 'type' => 'N', 'length' => 10, 'decimals' => 2],
        ['name' => 'ISDONE', 'type' => 'L', 'length' => 1, 'decimals' => 0],
        ['name' => 'ONDAY', 'type' => 'D', 'length' => 8, 'decimals' => 0],
    ];

    $recordLength = 1; // deletion flag
    foreach ($fields as $f) {
        $recordLength += $f['length'];
    }

    $fieldDescriptorBytes = count($fields) * 32;
    $headerLength = 32 + $fieldDescriptorBytes + 1; // +1 for the 0x0D terminator

    $records = [
        // active record: name has a real Cyrillic value (CP866-encoded) to prove decoding works.
        ['NAME' => mb_convert_encoding('Тест', 'CP866', 'UTF-8'), 'AMOUNT' => '   1500.50', 'ISDONE' => 'T', 'ONDAY' => '20260907'],
        // soft-deleted record (leading '*') -- must be skipped entirely.
        ['NAME' => 'Deleted', 'AMOUNT' => '    999.00', 'ISDONE' => 'F', 'ONDAY' => '20260101', '__deleted' => true],
        ['NAME' => 'Plain', 'AMOUNT' => '      0.00', 'ISDONE' => 'F', 'ONDAY' => '        '],
    ];

    $header = pack('C4', 0x03, 26, 9, 7); // version, year(2026-1900), month, day
    $header .= pack('V', count($records)); // record count
    $header .= pack('v', $headerLength);
    $header .= pack('v', $recordLength);
    $header .= str_repeat("\x00", 20); // reserved

    $descriptors = '';
    foreach ($fields as $f) {
        $descriptors .= str_pad($f['name'], 11, "\x00");
        $descriptors .= $f['type'];
        $descriptors .= str_repeat("\x00", 4);
        $descriptors .= chr($f['length']);
        $descriptors .= chr($f['decimals']);
        $descriptors .= str_repeat("\x00", 14);
    }
    $descriptors .= "\x0D";

    $body = '';
    foreach ($records as $record) {
        $body .= ($record['__deleted'] ?? false) ? '*' : ' ';
        foreach ($fields as $f) {
            $value = (string) $record[$f['name']];
            $body .= str_pad($value, $f['length'], $f['type'] === 'C' ? ' ' : ' ', $f['type'] === 'N' ? STR_PAD_LEFT : STR_PAD_RIGHT);
        }
    }

    return $header . $descriptors . $body;
}

return function (TestRunner $t): void {
    $t->group('DbfReader (synthetic fixture)');

    $path = tempnam(sys_get_temp_dir(), 'gdpd_dbf_test_') . '.dbf';
    file_put_contents($path, buildSyntheticDbf());

    try {
        $t->test('reads field names and record count', function (TestRunner $t) use ($path): void {
            $dbf = new DbfReader($path);
            $t->assertSame(['NAME', 'AMOUNT', 'ISDONE', 'ONDAY'], $dbf->fieldNames());
            $t->assertSame(3, $dbf->recordCount()); // includes the soft-deleted one at the header level
        });

        $t->test('decodes CP866 text, numeric, logical and date fields; skips deleted records', function (TestRunner $t) use ($path): void {
            $dbf = new DbfReader($path);
            $records = iterator_to_array($dbf->records());

            $t->assertSame(2, count($records), 'expected the soft-deleted record to be skipped');

            $t->assertSame('Тест', $records[0]['NAME']);
            $t->assertSame('1500.50', $records[0]['AMOUNT']);
            $t->assertSame(true, $records[0]['ISDONE']);
            $t->assertSame('2026-09-07', $records[0]['ONDAY']);

            $t->assertSame('Plain', $records[1]['NAME']);
            $t->assertSame(false, $records[1]['ISDONE']);
            $t->assertSame('', $records[1]['ONDAY']);
        });
    } finally {
        @unlink($path);
    }
};
