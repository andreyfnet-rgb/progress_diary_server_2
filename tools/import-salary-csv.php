<?php

declare(strict_types=1);

/**
 * Daily refresh counterpart to export-salary-views-to-csv.ps1: replaces
 * the full contents of wv_stpprep_week/wv_datein_group_week in the
 * separate salary database with the CSVs' current rows.
 *
 * Unlike tools/import-csv.php (a one-time historical import) this is a
 * full TRUNCATE + re-insert every run, on purpose: these tables are a
 * snapshot of "current state" (this week's/this teacher's running
 * totals), not an accumulating log, and Access's own week-bucketing views
 * they're sourced from work the same way -- there's no stable row
 * identity to reconcile against, so a full replace is both simpler and
 * more correct than trying to diff old vs new snapshots.
 *
 * Usage: php import-salary-csv.php /path/to/salary_csv_export
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\PhoneFormatting;

$tables = ['wv_stpprep_week', 'wv_datein_group_week'];

$csvDir = $argv[1] ?? null;
if ($csvDir === null || !is_dir($csvDir)) {
    fwrite(STDERR, "Usage: php import-salary-csv.php <salary-csv-directory>\n");
    exit(1);
}

$config = new Config(__DIR__ . '/../config.ini');
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config->get('salary_db', 'host', '127.0.0.1'),
        (int) $config->get('salary_db', 'port', '3306'),
        $config->get('salary_db', 'name')
    ),
    $config->get('salary_db', 'user', 'root'),
    $config->get('salary_db', 'pass', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$grandTotal = 0;
$startedAt = microtime(true);

foreach ($tables as $table) {
    $file = $csvDir . DIRECTORY_SEPARATOR . $table . '.csv';
    if (!is_file($file)) {
        echo "SKIP  {$table} (no {$table}.csv found)\n";
        continue;
    }

    try {
        $count = importTable($pdo, $table, $file);
        $grandTotal += $count;
        echo "OK    {$table}: {$count} rows\n";
    } catch (\Throwable $e) {
        echo "ERROR {$table}: " . $e->getMessage() . "\n";
    }
}

$elapsed = round(microtime(true) - $startedAt, 1);
echo "\nDone: {$grandTotal} rows total in {$elapsed}s.\n";

/**
 * Loads into a freshly-created shadow table and only swaps it in on
 * success (atomic RENAME), rather than truncating the live table up
 * front -- a bad CSV or a mid-import failure must never leave real
 * teachers' salary numbers empty or half-refreshed.
 */
function importTable(PDO $pdo, string $table, string $file): int
{
    $handle = fopen($file, 'r');
    if ($handle === false) {
        throw new \RuntimeException("could not open {$file}");
    }

    // Same fgetcsv gotcha as tools/import-csv.php: disable PHP's
    // non-standard backslash-escape handling so this parses strictly per
    // RFC 4180 like the exporter actually writes.
    $columns = fgetcsv($handle, 0, ',', '"', '');
    if ($columns === false || $columns === null) {
        fclose($handle);
        throw new \RuntimeException('empty file (no header row)');
    }

    $shadowTable = $table . '_import_new';
    $oldTable = $table . '_import_old';
    $pdo->exec("DROP TABLE IF EXISTS `{$shadowTable}`, `{$oldTable}`");
    $pdo->exec("CREATE TABLE `{$shadowTable}` LIKE `{$table}`");

    $quotedColumns = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $statement = $pdo->prepare("INSERT INTO `{$shadowTable}` ({$quotedColumns}) VALUES ({$placeholders})");

    // The real export has phone stored two different ways depending on
    // the row ("7-926-599-01-31" for most, "+7 (926) 599-01-77" for a
    // small minority -- ~1-2% of rows, confirmed against real data) --
    // SalaryService::queryGroup() does an exact match against
    // PhoneFormatting::insertSalaryPhoneDashes()'s dash-only output, so an
    // un-normalized "+7 (...)" row would simply never match any query and
    // silently look like that teacher has no data for the period.
    // Normalizing here (not at query time) keeps the read side simple and
    // makes the stored data self-consistent.
    $phoneIndex = array_search('phone', $columns, true);

    $pdo->beginTransaction();
    $count = 0;
    try {
        $lineNumber = 1;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $lineNumber++;
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }

            if (count($row) !== count($columns)) {
                throw new \RuntimeException(sprintf(
                    'line %d has %d field(s), expected %d (columns: %s)',
                    $lineNumber,
                    count($row),
                    count($columns),
                    implode(', ', $columns)
                ));
            }

            $values = array_map(static fn ($value) => $value === '\N' ? null : $value, $row);
            if ($phoneIndex !== false && $values[$phoneIndex] !== null && $values[$phoneIndex] !== '') {
                $digits = PhoneFormatting::getDigits($values[$phoneIndex]);
                $values[$phoneIndex] = $digits === '' ? $values[$phoneIndex] : PhoneFormatting::insertSalaryPhoneDashes($digits);
            }
            $statement->execute($values);
            $count++;

            if ($count % 500 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DROP TABLE IF EXISTS `{$shadowTable}`");
        fclose($handle);
        throw $e;
    }
    fclose($handle);

    $pdo->exec("RENAME TABLE `{$table}` TO `{$oldTable}`, `{$shadowTable}` TO `{$table}`");
    $pdo->exec("DROP TABLE `{$oldTable}`");

    return $count;
}
