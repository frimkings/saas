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
            $raw      = (string) $response->getBody();
            $body     = json_decode($raw, true) ?? [];

            Log::info('EazismsPro send response', ['phone' => $to, 'body' => $body]);

            if (isset($body['status']) && strtolower($body['status']) === 'success') {
                return [
                    'success'    => true,
                    'message_id' => $body['message_id'] ?? $body['data']['message_id'] ?? $body['id'] ?? null,
                    'response'   => $body,
                ];
            }

            return ['success' => false, 'error' => $body['message'] ?? $body['error'] ?? $raw];
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
