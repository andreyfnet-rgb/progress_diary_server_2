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

    /** @var array<string, array{name: string, count: int}> */
    private array $unknownDepartments = [];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    /**
     * Every config section named [DBin] or [DBin_<suffix>] is one 1C Fitness
     * server (Moscow, Екатеринбург, Пермь ...). Each is a separate 1C
     * database with its own numbering, so ids from [DBin_<suffix>] are
     * stored as "<prefix>:<id>" (prefix defaults to the lowercased suffix)
     * -- otherwise Екатеринбург's department "000000001" would be read as
     * Moscow's Жуковка, and two different people with the same 1C client
     * number would merge. [DBin] keeps bare ids, as before. l/p fall back to
     * [DBin]'s when a section leaves them out.
     */
    public function sync(): void
    {
        $sources = 0;
        foreach ($this->config->sectionNames() as $section) {
            if ($section !== 'DBin' && !str_starts_with($section, 'DBin_')) {
                continue;
            }
            $url = $this->config->get($section, 'url');
            if ($url === '') {
                continue;
            }
            $sources++;
            $prefix = $section === 'DBin' ? '' : $this->config->get($section, 'prefix', strtolower(substr($section, 5)));
            $this->syncSource(
                $url,
                $this->config->get($section, 'l', $this->config->get('DBin', 'l')),
                $this->config->get($section, 'p', $this->config->get('DBin', 'p')),
                $prefix
            );
        }

        if ($sources === 0) {
            $this->log->log('shed_1c', 'ERROR: no [DBin] url is configured');
        }
    }

    private function syncSource(string $url, string $login, string $password, string $source): void
    {
        $label = $source === '' ? '' : "[{$source}] ";
        $requestUrl = $url . 'ExportSchedule?Date=' . date('Ymd');
        $this->log->log('shed_1c', $label . 'GET URL: ' . $requestUrl);

        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_USERPWD => $login . ':' . $password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->log->log('shed_1c', $label . 'ERROR GET URL: ' . $error);
            return;
        }

        $body = (string) $response;
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            // The real 1C server sends a UTF-8 BOM before the '[' -- harmless
            // for anything that reads text loosely, but json_decode() treats
            // it as invalid JSON and fails silently (returns null with no
            // usable error short of json_last_error()). Confirmed against a
            // real response during first live testing.
            $body = substr($body, 3);
        }

        $items = json_decode($body, true);
        if (!is_array($items)) {
            // Mirrors the original's error_in_json.txt: the response body is
            // the single most useful piece of information for diagnosing
            // this (wrong credentials, an HTML error page, an unexpected 1C
            // response shape), so it's worth keeping around even though it's
            // not itself parseable JSON.
            $this->log->log('shed_1c', $label . "ERROR GET in STL: response is not a JSON array (HTTP {$httpStatus})");
            $this->log->log('shed_1c_error_response', $label . substr($body, 0, 4000));
            return;
        }

        $this->processItems($items, $source);
        $this->log->log('shed_1c', $label . 'Успешная загрузка в ' . date('d.m.Y H:i:s'));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param string $source id prefix of the 1C server these items came from ('' for [DBin])
     */
    public function processItems(array $items, string $source = ''): void
    {
        $this->unknownDepartments = [];
        foreach ($items as $index => $item) {
            try {
                $this->processItem($item, $source);
            } catch (\Throwable $e) {
                $this->log->log('shed_1c', "ERROR PARSE[{$index}]: " . $e->getMessage());
            }
        }

        // A club that 1C sends lessons for but that isn't linked yet is
        // logged with the exact clb_id1c value to set -- that's how a newly
        // connected server's department ids are found.
        foreach ($this->unknownDepartments as $id => $info) {
            $this->log->log('shed_1c', "Клуб 1С не подключён: clb_id1c='{$id}' ({$info['name']}), уроков пропущено: {$info['count']}");
        }
    }

    /** @param array<string, mixed> $item */
    private function processItem(array $item, string $source): void
    {
        $instructor = (array) ($item['Instructor'] ?? []);
        $client = (array) ($item['Client'] ?? []);
        $class = (array) ($item['Class'] ?? []);
        $department = (array) ($item['Department'] ?? []);
        $room = (array) ($item['Room'] ?? []);

        if ($this->isExcluded('room_code', $this->scoped($source, (string) ($room['Code'] ?? '')))
            || $this->isExcluded('class_code', $this->scoped($source, (string) ($class['Code'] ?? '')))) {
            return;
        }

        $departmentId = $this->scoped($source, (string) ($department['Id'] ?? ''));
        $clbId = $this->findClubId($departmentId);
        if ($clbId === null && $departmentId !== '') {
            $this->unknownDepartments[$departmentId] ??= ['name' => (string) ($department['Name'] ?? ''), 'count' => 0];
            $this->unknownDepartments[$departmentId]['count']++;
            return;
        }

        $clnId = null;
        $clientPhone = (string) ($client['Phone'] ?? '');
        if ($clientPhone !== '') {
            $clnId = $this->findOrCreateClient(
                explode(',', $clientPhone)[0],
                (string) ($client['Name'] ?? ''),
                $this->scoped($source, trim((string) ($client['Id'] ?? '')))
            );
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
            // shdl_dtlesend=0 matches the real Access data's own convention
            // for a freshly-scheduled, not-yet-taught lesson (see the same
            // fix/comment in ScheduleDbfImportService::importRow) --
            // without it, dp.galladance.com's own "list this teacher's
            // pending lessons" query (pd.php, filters on shdl_dtlesend=0)
            // never matches a NULL, so today's real lessons synced via 1C
            // were silently invisible on the teacher's own page.
            $this->db->execute(
                'INSERT INTO schedule (shdl_idcln, shdl_idclb, shdl_idprp, shdl_dtleson, shdl_dtlesoff, shdl_nameless, shdl_nameroom, shdl_dtlesend, shdl_del)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
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

    private function scoped(string $source, string $id): string
    {
        return ($source === '' || $id === '') ? $id : $source . ':' . $id;
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

    /**
     * Matches on the client's 1C card Id when 1C sends one, so two 1C cards
     * that share a phone (a data-entry mistake, or relatives) stay two
     * clients here instead of one student's lessons showing under the
     * other's name. 1C is the source of truth for a matched card: its name,
     * and its phone when no other client already holds that number, are
     * copied over on every sync.
     *
     * Rows created before cln_id1c existed are "claimed" by the first 1C
     * card that arrives with their phone. Items with no Id fall back to the
     * original phone-only match.
     */
    private function findOrCreateClient(string $phoneRaw, string $name, string $id1c): ?string
    {
        $phone = preg_replace('/\D/', '', $phoneRaw);
        if (strlen($phone) !== 11) {
            return null;
        }
        $name = trim($name);

        if ($id1c === '') {
            $existing = $this->db->query('SELECT cln_id FROM Client WHERE cln_phone = ? ORDER BY cln_id LIMIT 1', [$phone]);
            return count($existing) > 0 ? (string) $existing[0]['cln_id'] : $this->insertClient($name, $phone, null);
        }

        $byId = $this->db->query('SELECT cln_id, cln_name, cln_phone FROM Client WHERE cln_id1c = ? ORDER BY cln_id LIMIT 1', [$id1c]);
        if (count($byId) > 0) {
            $this->syncClientCard($byId[0], $name, $phone);
            return (string) $byId[0]['cln_id'];
        }

        $unclaimed = $this->db->query(
            "SELECT cln_id, cln_name, cln_phone FROM Client
             WHERE cln_phone = ? AND (cln_id1c IS NULL OR cln_id1c = '')
             ORDER BY cln_id LIMIT 1",
            [$phone]
        );
        if (count($unclaimed) > 0) {
            $this->db->execute('UPDATE Client SET cln_id1c = ? WHERE cln_id = ?', [$id1c, $unclaimed[0]['cln_id']]);
            $this->syncClientCard($unclaimed[0], $name, $phone);
            return (string) $unclaimed[0]['cln_id'];
        }

        $holder = $this->db->query('SELECT cln_id, cln_id1c FROM Client WHERE cln_phone = ? LIMIT 1', [$phone]);
        if (count($holder) > 0) {
            $this->log->log(
                'shed_1c',
                "Телефон {$phone} уже у клиента id={$holder[0]['cln_id']} (1С {$holder[0]['cln_id1c']}); "
                . "для карточки 1С {$id1c} ({$name}) создан отдельный клиент"
            );
        }

        return $this->insertClient($name, $phone, $id1c);
    }

    /** @param array{cln_id: mixed, cln_name: mixed, cln_phone: mixed} $row */
    private function syncClientCard(array $row, string $name, string $phone): void
    {
        if ($name !== '' && $name !== (string) $row['cln_name']) {
            $this->db->execute('UPDATE Client SET cln_name = ? WHERE cln_id = ?', [$name, $row['cln_id']]);
        }

        if ($phone !== (string) $row['cln_phone']) {
            $taken = $this->db->query('SELECT cln_id FROM Client WHERE cln_phone = ? AND cln_id <> ? LIMIT 1', [$phone, $row['cln_id']]);
            if (count($taken) === 0) {
                $this->db->execute('UPDATE Client SET cln_phone = ? WHERE cln_id = ?', [$phone, $row['cln_id']]);
            } else {
                $this->log->log(
                    'shed_1c',
                    "Телефон клиента id={$row['cln_id']} в 1С сменился на {$phone}, но этот номер уже у клиента id={$taken[0]['cln_id']} -- оставлен старый"
                );
            }
        }
    }

    private function insertClient(string $name, string $phone, ?string $id1c): string
    {
        $this->db->execute(
            'INSERT INTO Client (cln_name, cln_phone, cln_info, cln_id1c) VALUES (?, ?, ?, ?)',
            [$name, $phone, (new \DateTimeImmutable())->format('d.m.Y H:i:s'), $id1c]
        );
        return (string) $this->db->pdo()->lastInsertId();
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
