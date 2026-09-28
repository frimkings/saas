<?php

namespace App\Services\Messaging\Drivers;

use App\Services\Messaging\SmsCredentials;
use App\Services\Messaging\SmsDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

/**
 * EazismsPro GET API:
 *   {base_url}?action=send-sms&api_key=KEY&to=PHONE[&from=SENDERID]&sms=MESSAGE
 *   {base_url}?action=check-balance&api_key=KEY&response=json
 *
 * Sender IDs must be pre-registered in the EazismsPro dashboard; omitting `from`
 * lets the account's default sender be used.
 */
class EazismsDriver implements SmsDriver
{
    /**
     * Read a send reply. EazismsPro answers success in more than one shape — {"status":"success"},
     * {"code":"ok","message":"Successfully Sent"}, or just the words "Successfully Sent" — so any
     * of them counts; anything else (e.g. "Invalid Sender id") is a failure with that reason.
     */
    public static function interpret(array $body, string $raw): array
    {
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        $code = strtolower(trim((string) ($body['code'] ?? '')));
        $message = trim((string) ($body['message'] ?? $body['msg'] ?? ($body ? '' : $raw)));
        $said = static fn (string $text) => (bool) preg_match('/\b(successfully\s+sent|sent\s+successfully|message\s+sent)\b/i', $text);

        $ok = in_array($status, ['success', 'successful', 'ok', 'sent', 'true', '1'], true)
            || ($body['status'] ?? null) === true
            || in_array($code, ['ok', 'success', '1000', '200'], true)
            || $said($message);

        if ($ok && empty($body['error'])) {
            return [
                'success'    => true,
                'message_id' => $body['message_id'] ?? $body['data']['message_id'] ?? $body['data']['id'] ?? $body['id'] ?? null,
                'response'   => $body ?: ['raw' => $raw],
            ];
        }

        return ['success' => false, 'error' => ($body['message'] ?? $body['error'] ?? null) ?: ($raw !== '' ? $raw : 'Unknown gateway response.')];
    }

    public function send(SmsCredentials $credentials, string $to, string $message): array
    {
        $query = [
            'action'  => 'send-sms',
            'api_key' => $credentials->key,
            'to'      => $to,
            'sms'     => $message,
        ];

        if (!empty(trim($credentials->sender ?? ''))) {
            $query['from'] = trim($credentials->sender);
        }

        try {
            $response = (new Client(['timeout' => 15]))->get($credentials->url, ['query' => $query]);
            $raw      = trim((string) $response->getBody());
            $body     = json_decode($raw, true);
            $body     = is_array($body) ? $body : [];   // plain-text or JSON-string replies are read from $raw

            Log::info('EazismsPro send response', ['phone' => $to, 'body' => $body ?: $raw]);

            return self::interpret($body, $raw);
        } catch (ClientException $e) {
            $raw  = (string) $e->getResponse()->getBody();
            $body = json_decode($raw, true);

            return ['success' => false, 'error' => ($body['message'] ?? $body['error'] ?? null) ?: $raw];
        } catch (\Exception $e) {
            // Timeouts, DNS and 5xx responses are worth retrying; 4xx rejections are not.
            return ['success' => false, 'error' => $e->getMessage(), 'retryable' => true];
        }
    }

    public function balance(SmsCredentials $credentials): array
    {
        try {
            $response = (new Client(['timeout' => 10]))->get($credentials->url, [
                'query' => [
                    'action'   => 'check-balance',
                    'api_key'  => $credentials->key,
                    'response' => 'json',
                ],
            ]);

            $body = json_decode($response->getBody(), true) ?? [];
            Log::info('EazismsPro balance response', $body);

            return ['success' => true, 'response' => $body];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
