<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\Db;
use Gdpd\Infrastructure\DbfReader;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\RandomString;

/**
 * Ports addschedule_dbf (SrvMetod.pas): for each club with clb_indbf=1,
 * reads that club's dBase export (dropped into a local folder by the
 * user's own transport mechanism -- this class only reads what's already
 * there, it doesn't fetch anything itself), matches/creates
 * prepod/Client rows by phone, and upserts `schedule` rows.
 *
 * Confirmed against a real sample file (dp179.dbf) for field names,
 * value shapes (phone numbers like "9264325077 (м)", time ranges like
 * "21:00 - 21:45") and text encoding (CP866, handled by DbfReader).
 *
 * One filter's exact original text could not be recovered: SrvMetod.pas
 * checked the STATUSNAME field for two Cyrillic substrings (12 and 10
 * characters) to decide shdl_del, but the source file's encoding was
 * already corrupted (replaced with U+FFFD) by the time it was read for
 * this port. Using the common word roots "тмен" (отменен/отменена/...,
 * "cancelled") and "еренес" (перенесен/..., "rescheduled") as a
 * defensible reconstruction -- same substring-matching style the
 * original uses elsewhere (e.g. "ыходн" for "выходной" in sqldbf.sql).
 * Flagged for confirmation once real STATUSNAME cancellation text is
 * available to check against.
 */
final class ScheduleDbfImportService
{
    /** @var list<string> substrings of SERVICENAM that exclude a row, ported from sqldbf.sql */
    private const EXCLUDED_SERVICE_SUBSTRINGS = ['ыходн', 'рупп', 'ссист', 'ssist', 'ewcom', 'тпус', 'водн'];

    public function __construct(
        private readonly Db $db,
        private readonly Logger $log,
    ) {
    }

    public function importAll(string $importDir): void
    {
        $clubs = $this->db->query('SELECT * FROM clubs WHERE clb_indbf = 1');

        foreach ($clubs as $club) {
            try {
                $this->importClub($club, $importDir);
            } catch (\Throwable $e) {
                $this->log->log('shed_dbf', "Error club {$club['clb_id']}: " . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $club */
    private function importClub(array $club, string $importDir): void
    {
        $fileName = $this->windowsBasename((string) $club['clb_patchindb']);
        if ($fileName === '') {
            return;
        }

        $path = rtrim($importDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($path)) {
            $this->log->log('shed_dbf', "Error: file {$fileName} not found for club {$club['clb_id']}");
            return;
        }

        $mtime = date('Y-m-d H:i:s', (int) filemtime($path));
        if ($mtime === $club['clb_timeindb']) {
            return; // unchanged since last import, matching the legacy mtime check
        }

        $dbf = new DbfReader($path);
        foreach ($dbf->records() as $row) {
            try {
                $this->importRow($club, $row);
            } catch (\Throwable $e) {
                $this->log->log('shed_dbf', "{$fileName} ERROR ADD: " . $e->getMessage());
            }
        }

        $this->db->execute('UPDATE clubs SET clb_timeindb = ? WHERE clb_id = ?', [$mtime, $club['clb_id']]);
    }

    /** @param array<string, mixed> $club @param array<string, mixed> $row */
    private function importRow(array $club, array $row): void
    {
        $serviceName = (string) $row['SERVICENAM'];
        foreach (self::EXCLUDED_SERVICE_SUBSTRINGS as $excluded) {
            if (mb_stripos($serviceName, $excluded) !== false) {
                return;
            }
        }
        if (mb_stripos((string) $row['CATEGORYNA'], 'онкур') !== false) {
            return;
        }
        if ($row['ISGROUP'] === true) {
            return;
        }

        $prpPhone = $this->cleanPhone((string) $row['RESOURCEPH']);
        $clnPhone = $this->cleanPhone((string) $row['CLIENTPHON']);
        if ($prpPhone === null || $clnPhone === null) {
            $this->log->log('shed_dbf', 'ERROR ADD: NO CLN OR PROP');
            return;
        }

        $prpId = $this->findOrCreatePrepod($prpPhone, (string) $row['RESOURCENA'], (string) $row['RESOURCEID']);
        $clnId = $this->findOrCreateClient($clnPhone, (string) $row['CLIENTNAME'], (string) $row['CLIENTIDX']);

        $timeInterval = str_replace(' ', '', (string) $row['TIMEINTERV']);
        $timeOn = substr($timeInterval, 0, 5);
        $timeOff = substr($timeInterval, 6, 5);
        $day = (string) $row['DAYVALUE'];
        if ($day === '' || $timeOn === '' || $timeOff === '') {
            return;
        }

        $dtLesOn = "{$day} {$timeOn}:00";
        $dtLesOff = "{$day} {$timeOff}:00";

        $existing = $this->db->query(
            'SELECT shdl_id FROM schedule
             WHERE shdl_idcln = ? AND shdl_idprp = ? AND shdl_idclb = ? AND shdl_nameless = ?
               AND shdl_dtleson >= ? AND shdl_dtlesoff <= ?',
            [$clnId, $prpId, $club['clb_id'], $serviceName, $dtLesOn, $dtLesOff]
        );

        $statusName = (string) $row['STATUSNAME'];
        $isCancelledOrMoved = mb_stripos($statusName, 'тмен') !== false || mb_stripos($statusName, 'еренес') !== false;

        if (count($existing) === 0) {
            $this->db->execute(
                'INSERT INTO schedule (shdl_idcln, shdl_dtleson, shdl_dtlesoff, shdl_idclb, shdl_nameless, shdl_nameroom, shdl_idprp, shdl_del)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$clnId, $dtLesOn, $dtLesOff, $club['clb_id'], $serviceName, (string) $row['CATEGORYNA'], $prpId, $isCancelledOrMoved ? 1 : 0]
            );
        } else {
            $this->db->execute(
                'UPDATE schedule SET shdl_idcln = ?, shdl_dtleson = ?, shdl_dtlesoff = ?, shdl_idclb = ?,
                     shdl_nameless = ?, shdl_nameroom = ?, shdl_idprp = ?, shdl_del = ?
                 WHERE shdl_id = ?',
                [$clnId, $dtLesOn, $dtLesOff, $club['clb_id'], $serviceName, (string) $row['CATEGORYNA'], $prpId, $isCancelledOrMoved ? 1 : 0, $existing[0]['shdl_id']]
            );
        }
    }

    /**
     * PHP's basename() only treats backslash as a path separator on
     * Windows -- on Linux (the real target hosting) it doesn't, so
     * basename("C:\GDPD_db\dp499.dbf") would return the whole string
     * unchanged there even though it works fine when tested locally on a
     * Windows dev machine. clb_patchindb always holds a Windows-style
     * path (written by the original Delphi app), regardless of which OS
     * this code itself runs on, so this splits on both '/' and '\'
     * explicitly instead of relying on the platform-dependent builtin.
     */
    private function windowsBasename(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $lastSlash = strrpos($normalized, '/');
        return $lastSlash === false ? $normalized : substr($normalized, $lastSlash + 1);
    }

    /** Strips the "(...)" suffix dBase exports append to phone numbers and normalizes to a leading '7'. Returns null if the result isn't 11 digits. */
    private function cleanPhone(string $raw): ?string
    {
        $parenPos = strpos($raw, '(');
        $digits = $parenPos === false ? $raw : rtrim(substr($raw, 0, $parenPos));
        $phone = '7' . $digits;
        return strlen($phone) === 11 ? $phone : null;
    }

    private function findOrCreatePrepod(string $phone, string $name, string $idFoxRaw): string
    {
        $existing = $this->db->query('SELECT prp_id FROM prepod WHERE prp_phone = ?', [$phone]);
        $now = (new \DateTimeImmutable())->format('d.m.Y H:i:s');
        $idFox = (int) (float) $idFoxRaw; // dBase numeric fields come through as e.g. "7837.00000"

        if (count($existing) === 0) {
            $this->db->execute(
                'INSERT INTO prepod (prp_name, prp_idfox, prp_phone, prp_pass, prp_info) VALUES (?, ?, ?, ?, ?)',
                [$name, $idFox, $phone, RandomString::digits(5), $now]
            );
            return (string) $this->db->query('SELECT prp_id FROM prepod WHERE prp_phone = ?', [$phone])[0]['prp_id'];
        }

        $this->db->execute('UPDATE prepod SET prp_info = ? WHERE prp_phone = ?', [$now, $phone]);
        return (string) $existing[0]['prp_id'];
    }

    private function findOrCreateClient(string $phone, string $name, string $idFoxRaw): string
    {
        $existing = $this->db->query('SELECT cln_id FROM Client WHERE cln_phone = ?', [$phone]);
        $now = (new \DateTimeImmutable())->format('d.m.Y H:i:s');

        if (count($existing) === 0) {
            $this->db->execute(
                'INSERT INTO Client (cln_name, cln_phone, cln_idfox, cln_info) VALUES (?, ?, ?, ?)',
                [$name, $phone, $idFoxRaw, $now]
            );
            return (string) $this->db->query('SELECT cln_id FROM Client WHERE cln_phone = ?', [$phone])[0]['cln_id'];
        }

        $this->db->execute('UPDATE Client SET cln_info = ? WHERE cln_phone = ?', [$now, $phone]);
        return (string) $existing[0]['cln_id'];
    }
}
