<?php

declare(strict_types=1);

namespace Gdpd\Domain;

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;
use Gdpd\Infrastructure\PhoneFormatting;
use Gdpd\Infrastructure\SmsGateway;

/**
 * Ports get_author and get_getpass (SrvMetod.pas) -- the login and
 * password-recovery routes 3.php on dp.galladance.com depends on for
 * every page (it redirects to an error state whenever author's result
 * isn't a non-empty match).
 */
final class AuthService
{
    public function __construct(
        private readonly Db $db,
        private readonly SmsGateway $sms,
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    /**
     * Matches get_author exactly: only prp_id is selected (not the whole
     * row), so a successful login's JSON is `[{"prp_id":"..."}]`, and any
     * non-match -- including a missing/empty request body -- is the plain
     * string "no" (not a JSON value).
     */
    public function getAuthor(?array $filter): string
    {
        try {
            if ($filter === null || count($filter) === 0) {
                return 'no';
            }
            if (!array_key_exists('login', $filter) || !array_key_exists('pass', $filter)) {
                return 'no';
            }

            $rows = $this->db->query(
                'SELECT prp_id FROM prepod WHERE prp_phone = ? AND prp_pass = ? AND prp_out = 0',
                [JsonValue::toString($filter['login']), JsonValue::toString($filter['pass'])]
            );

            if (count($rows) === 0) {
                return 'no';
            }

            $result = array_map(
                static fn(array $row): array => ['prp_id' => (string) $row['prp_id']],
                $rows
            );
            $json = json_encode($result, JSON_UNESCAPED_UNICODE);
            return $json === false ? 'Error: failed to encode result' : $json;
        } catch (\Throwable $e) {
            $this->log->log('ERROR_uthor', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Matches get_getpass: normalizes the phone to the stored 7XXXXXXXXXX
     * shape, looks up the teacher, and sends their existing password by
     * SMS if found. "no" if nobody matches; the SMS gateway itself can
     * still fail (e.g. not configured), in which case the SmsGateway
     * error text is returned in place of "SMS ok", same as the original
     * propagating sendsms's return value directly.
     */
    public function getPass(?array $filter): string
    {
        try {
            if ($filter === null) {
                return 'ERROR: JSON is null';
            }
            if (count($filter) === 0 || !array_key_exists('phone', $filter)) {
                return 'ERROR: JSON has no text';
            }

            $phone = PhoneFormatting::getDigits(JsonValue::toString($filter['phone']));
            if (strlen($phone) === 10) {
                $phone = '7' . $phone;
            }
            if (strlen($phone) === 11) {
                $phone = '7' . substr($phone, 1);
            }

            $rows = $this->db->query(
                'SELECT * FROM prepod WHERE prp_phone = ? AND prp_out = 0 ORDER BY prp_name',
                [$phone]
            );

            if (count($rows) === 0) {
                return 'no';
            }

            $smsText = $this->config->get('sms', 'smstext');
            return $this->sms->send($rows[0]['prp_phone'], $smsText . '  ' . $rows[0]['prp_pass']);
        } catch (\Throwable $e) {
            $this->log->log('ERROR_getprep', $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
}
