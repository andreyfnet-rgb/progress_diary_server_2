<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Ports the legacy `sendsms` (SrvMetod.pas): POSTs a JSON envelope to the
 * SMS gateway with HTTP Basic auth. Only the currently-active gateway path
 * is ported -- the old GET-based mcommunicator.ru path (sendsms_old in
 * GDPDAP_run.pas) was already dead code in the original (never called from
 * apicommand, only from a manual test button in the desktop UI) and isn't
 * carried over, same as that project's earlier .NET port decided.
 */
final class SmsGateway
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    public function send(string $phone, string $text): string
    {
        $digits = PhoneFormatting::getDigits($phone);
        if (strlen($digits) === 11) {
            $digits = '7' . substr($digits, 1);
        }

        $apiUrl = $this->config->get('sms', 'apiurl_new');
        if ($apiUrl === '') {
            $this->log->log('send_sms_ERROR', 'SMS gateway not configured ([sms] apiurl_new is empty)');
            return 'Error: SMS gateway not configured';
        }

        $payload = json_encode([
            'messages' => [[
                'content' => ['short_text' => $text],
                'from' => ['sms_address' => 'GallaDance'],
                'to' => [['msisdn' => $digits]],
            ]],
        ]);

        $this->log->log('send_sms', "msid : {$digits}; message : \"{$text}\"");

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => $this->config->get('sms', 'log_new') . ':' . $this->config->get('sms', 'pass_new'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log->log('send_sms_ERROR', $error);
            return 'Error: ' . $error;
        }

        $this->log->log('send_sms', (string) $response);
        return 'SMS ok';
    }
}
