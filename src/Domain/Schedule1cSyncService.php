<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\RandomString;

/**
 * Ports addschedule_1c (SrvMetod.pas): fetches today's schedule from the 1C
 * server's REST export (GET {url}ExportSchedule?Date=yyyymmdd, HTTP Basic
 * auth from [DBin] l/p in config), and upserts `schedule` rows from it --
 * same shape and matching rules as ScheduleDbfImportService, but sourced
 * from a live HTTP call instead of a dropped-in file.
 *
 * Unlike the DBF path, this one does NOT create missing clubs (get_clb_id
 * in the original only ever looks up clb_id1c, it never inserts) -- an
 * item for an unknown department is just silently skipped, matching the
 * original.
 *
 * The cancelled-lesson status text was recovered by decoding the source
 * .pas file's raw bytes as Windows-1251 (the file read as UTF-8 showed
 * only replacement characters for the non-ASCII literal): the exact
 * comparison is against the single word "Отменено".
 */
final class Schedule1cSyncService
{
    private const CANCELLED_STATUS = 'Отменено';

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    public function sync(): void
    {
        $url = $this->config->get('DBin', 'url');
        if ($url === '') {
            $this->log->log('shed_1c', 'ERROR: [DBin] url is not configured');
            return;
        }

        $requestUrl = $url . 'ExportSchedule?Date=' . date('Ymd');
        $this->log->log('shed_1c', 'GET URL: ' . $requestUrl);

        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_USERPWD => $this->config->get('DBin', 'l') . ':' . $this->config->get('DBin', 'p'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log->log('shed_1c', 'ERROR GET URL: ' . $error);
            return;
        }

        $items = json_decode((string) $response, true);
        if (!is_array($items)) {
            $this->log->log('shed_1c', 'ERROR GET in STL: response is not a JSON array');
            return;
        }

        $this->processItems($items);
        $this->log->log('shed_1c', 'Успешная загрузка в ' . date('d.m.Y H:i:s'));
    }

    /** @param list<array<string, mixed>> $items */
    public function processItems(array $items): void
    {
        foreach ($items as $index => $item) {
            try {
                $this->processItem($item);
            } catch (\Throwable $e) {
                $this->log->log('shed_1c', "ERROR PARSE[{$index}]: " . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $item */
    private function processItem(array $item): void
    {
        $instructor = (array) ($item['Instructor'] ?? []);
        $client = (array) ($item['Client'] ?? []);
        $class = (array) ($item['Class'] ?? []);
        $department = (array) ($item['Department'] ?? []);
        $room = (array) ($item['Room'] ?? []);

        if ($this->isExcluded('room_code', (string) ($room['Code'] ?? '')) || $this->isExcluded('class_code', (string) ($class['Code'] ?? ''))) {
            return;
        }

        $clbId = $this->findClubId((string) ($department['Id'] ?? ''));

        $clnId = null;
        $clientPhone = (string) ($client['Phone'] ?? '');
        if ($clientPhone !== '') {
            $clnId = $this->findOrCreateClient(explode(',', $clientPhone)[0], (string) ($client['Name'] ?? ''));
        }

        $prpId = null;
        $instructorPhone = (string) ($instructor['Phone'] ?? '');
        if ($instructorPhone !== '') {
            $prpId = $this->findOrCreatePrepod(explode(',', $instructorPhone)[0], (string) ($instructor['Name'] ?? ''));
        }

        if ($clbId === null || $clnId === null || $prpId === null) {
            return;
        }

        $lessonName = (string) ($class['Name'] ?? '');
        $roomName = (string) ($room['Name'] ?? '');
        $dtLesOn = $this->parseJsonDate((string) ($class['DateTime_Start'] ?? ''));
        $dtLesOff = $this->parseJsonDate((string) ($class['DateTime_End'] ?? ''));
        $isCancelled = ((string) ($class['Status'] ?? '')) === self::CANCELLED_STATUS;

        $existing = $this->db->query(
            'SELECT shdl_id FROM schedule
             WHERE shdl_idcln = ? AND shdl_idprp = ? AND shdl_idclb = ? AND shdl_nameless = ?
               AND shdl_dtleson >= ? AND shdl_dtlesoff <= ?',
            [$clnId, $prpId, $clbId, $lessonName, $dtLesOn, $dtLesOff]
        );

        if (count($existing) === 0) {
            $this->db->execute(
                'INSERT INTO schedule (shdl_idcln, shdl_idclb, shdl_idprp, shdl_dtleson, shdl_dtlesoff, shdl_nameless, shdl_nameroom, shdl_del)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$clnId, $clbId, $prpId, $dtLesOn, $dtLesOff, $lessonName, $roomName, $isCancelled ? 1 : 0]
            );
        } else {
            $this->db->execute(
                'UPDATE schedule SET shdl_idcln = ?, shdl_idclb = ?, shdl_idprp = ?, shdl_dtleson = ?, shdl_dtlesoff = ?,
                     shdl_nameless = ?, shdl_nameroom = ?, shdl_del = ?
                 WHERE shdl_id = ?',
                [$clnId, $clbId, $prpId, $dtLesOn, $dtLesOff, $lessonName, $roomName, $isCancelled ? 1 : 0, $existing[0]['shdl_id']]
            );
        }

        $this->reconcileCancelledAndMoved();
    }

    /**
     * Literal port of chek_dell_add. Its intent (per the original's variable
     * names) is: a lesson already marked del=true that was ALSO already
     * marked completed (shdl_datecc set) might really have just been moved
     * to a fresh slot at the exact same date/time -- if a second, live
     * (del=false, not yet completed) row exists for the same
     * client/teacher/club/lesson-name/time, treat that as the real one.
     *
     * Traced through the original's unqualified `with` scoping, the actual
     * assignments are: the CANCELLED row's completion fields get overwritten
     * with the LIVE row's (null) ones, and only the LIVE row's shdl_relocat
     * gets touched (set to the cancelled row's shdl_relocat, which the WHERE
     * clause guarantees is already false -- a no-op). The cancelled row's
     * OWN shdl_relocat is never set true anywhere in this routine, so the
     * outer WHERE (shdl_relocat=false) matches it again on every future run;
     * ported as-is rather than "fixed", since there's no way to confirm from
     * source alone whether relocat was meant to land on the other row.
     */
    private function reconcileCancelledAndMoved(): void
    {
        try {
            $cancelled = $this->db->query(
                'SELECT * FROM schedule WHERE shdl_del = 1 AND shdl_datecc IS NOT NULL AND shdl_relocat = 0'
            );

            foreach ($cancelled as $row) {
                $live = $this->db->query(
                    'SELECT shdl_id, shdl_dtlesend, shdl_datecc FROM schedule
                     WHERE shdl_idcln = ? AND shdl_del = 0 AND shdl_datecc IS NULL
                       AND shdl_idprp = ? AND shdl_idclb = ? AND shdl_nameless = ?
                       AND shdl_dtleson = ? AND shdl_dtlesoff = ?',
                    [$row['shdl_idcln'], $row['shdl_idprp'], $row['shdl_idclb'], $row['shdl_nameless'], $row['shdl_dtleson'], $row['shdl_dtlesoff']]
                );

                if (count($live) === 0) {
                    continue;
                }

                $this->db->execute(
                    'UPDATE schedule SET shdl_dtlesend = ?, shdl_datecc = ? WHERE shdl_id = ?',
                    [$live[0]['shdl_dtlesend'], $live[0]['shdl_datecc'], $row['shdl_id']]
                );
                $this->db->execute('UPDATE schedule SET shdl_relocat = 0 WHERE shdl_id = ?', [$live[0]['shdl_id']]);
            }
        } catch (\Throwable $e) {
            $this->log->log('ERROR_chek_dell_add', $e->getMessage());
        }
    }

    private function isExcluded(string $column, string $code): bool
    {
        if ($code === '') {
            return false;
        }
        return count($this->db->query("SELECT 1 FROM exclude_1c WHERE {$column} = ?", [$code])) > 0;
    }

    private function findClubId(string $dep1cId): ?string
    {
        if ($dep1cId === '') {
            return null;
        }
        $rows = $this->db->query('SELECT clb_id FROM clubs WHERE clb_in1с = 1 AND clb_id1c = ?', [$dep1cId]);
        return count($rows) === 0 ? null : (string) $rows[0]['clb_id'];
    }

    private function findOrCreateClient(string $phoneRaw, string $name): ?string
    {
        $phone = preg_replace('/\D/', '', $phoneRaw);
        if (strlen($phone) !== 11) {
            return null;
        }

        $existing = $this->db->query('SELECT cln_id FROM Client WHERE cln_phone = ?', [$phone]);
        if (count($existing) === 0) {
            $this->db->execute(
                'INSERT INTO Client (cln_name, cln_phone, cln_info) VALUES (?, ?, ?)',
                [$name, $phone, (new \DateTimeImmutable())->format('d.m.Y H:i:s')]
            );
            return (string) $this->db->query('SELECT cln_id FROM Client WHERE cln_phone = ?', [$phone])[0]['cln_id'];
        }

        return (string) $existing[0]['cln_id'];
    }

    private function findOrCreatePrepod(string $phoneRaw, string $name): ?string
    {
        $phone = preg_replace('/\D/', '', $phoneRaw);
        if (strlen($phone) !== 11) {
            return null;
        }

        $existing = $this->db->query('SELECT prp_id FROM prepod WHERE prp_phone = ?', [$phone]);
        if (count($existing) === 0) {
            $this->db->execute(
                'INSERT INTO prepod (prp_name, prp_phone, prp_pass, prp_info) VALUES (?, ?, ?, ?)',
                [$name, $phone, RandomString::digits(5), (new \DateTimeImmutable())->format('d.m.Y H:i:s')]
            );
            return (string) $this->db->query('SELECT prp_id FROM prepod WHERE prp_phone = ?', [$phone])[0]['prp_id'];
        }

        return (string) $existing[0]['prp_id'];
    }

    /**
     * The original parsed year/month/day/hour/minute/second by fixed
     * character offsets out of an ISO-8601-shaped string (plus a partial,
     * overlapping-offset stab at milliseconds that isn't reproduced here --
     * `schedule` has no fractional-seconds column, and lesson matching only
     * ever needs minute-level precision).
     */
    private function parseJsonDate(string $value): string
    {
        $year = substr($value, 0, 4);
        $month = substr($value, 5, 2);
        $day = substr($value, 8, 2);
        $hour = substr($value, 11, 2);
        $minute = substr($value, 14, 2);
        $second = substr($value, 17, 2);
        return "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";
    }
}
