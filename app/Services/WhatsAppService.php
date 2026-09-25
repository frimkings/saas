<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\Messaging\MessageDispatcher;
use App\Support\Messaging\MessageCategory;
use App\Support\Messaging\PhoneNumber;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private const API_VERSION = 'v19.0';
    private const BASE_URL    = 'https://graph.facebook.com';

    /**
     * Queue a pre-approved WhatsApp template message via Meta Cloud API.
     *
     * The template must be created and approved in Meta Business Manager first.
     * Body parameters are positional ({{1}}, {{2}}, ...) and must match the
     * approved template's parameter count in the same order.
     *
     * Each clinic uses its own Meta credentials, so WhatsApp is not metered
     * against the plan's SMS allowance.
     *
     * @param string      $to           Recipient phone number
     * @param string      $templateName Meta-approved template name
     * @param string      $languageCode Template language code (e.g. 'en', 'en_US')
     * @param array       $bodyParams   Ordered parameter values for body component
     * @param int|null    $patientId    For logging and opt-out checks
     * @param string|null $templateKey  For logging and opt-out checks
     */
    public function sendTemplate(
        string  $to,
        string  $templateName,
        string  $languageCode = 'en',
        array   $bodyParams   = [],
        ?int    $patientId    = null,
        ?string $templateKey  = null
    ): array {
        app(ClinicAccessService::class)->assertWritable();

        if ($error = $this->configurationError(Setting::getSettings())) {
            return ['success' => false, 'error' => $error];
        }

        $bodyParams = array_map('strval', array_values($bodyParams));
        $log = [
            'patient_id'   => $patientId,
            'template_key' => $templateKey,
            'channel'      => 'whatsapp',
            'recipient'    => PhoneNumber::normalize($to),
            'message'      => "WA template:{$templateName} → " . implode('|', $bodyParams),
        ];

        if ($reason = $this->optOutReason($patientId, $templateKey)) {
            SmsLog::create($log + ['status' => 'skipped', 'success' => false, 'error' => $reason]);
            return ['success' => false, 'skipped' => true, 'error' => $reason];
        }

        $log = SmsLog::create($log + ['status' => 'queued', 'success' => false]);

        if (!app(MessageDispatcher::class)->queues()) {
            return $this->deliverTemplate($log, $templateName, $languageCode, $bodyParams) + ['queued' => false, 'log_id' => $log->id];
        }

        app(MessageDispatcher::class)->dispatch(new SendWhatsAppMessage($log->id, $templateName, $languageCode, $bodyParams));

        return ['success' => true, 'queued' => true, 'log_id' => $log->id];
    }

    /** Called by SendWhatsAppMessage (or inline) to post a logged template message to Meta. */
    public function deliverTemplate(SmsLog $log, string $templateName, string $languageCode, array $bodyParams): array
    {
        $parameters = array_map(fn ($val) => ['type' => 'text', 'text' => (string) $val], $bodyParams);

        $result = $this->post($log->recipient, [
            'type'     => 'template',
            'template' => [
                'name'       => $templateName,
                'language'   => ['code' => $languageCode],
                'components' => empty($parameters) ? [] : [
                    ['type' => 'body', 'parameters' => $parameters],
                ],
            ],
        ]);

        Log::info('WhatsApp send', ['phone' => $log->recipient, 'template' => $templateName, 'ok' => $result['success']]);

        $log->update([
            'attempts'            => $log->attempts + 1,
            'status'              => $result['success'] ? 'sent' : 'failed',
            'success'             => $result['success'],
            'provider_message_id' => $result['message_id'] ?? null,
            'sent_at'             => $result['success'] ? now() : null,
            'error'               => $result['success'] ? null : $result['error'],
        ]);

        return $result;
    }

    /**
     * Send a test WhatsApp text message (only valid within a 24-hour customer-initiated window).
     * Useful for verifying credentials; NOT suitable for proactive outbound reminders.
     */
    public function sendText(string $to, string $message, ?int $patientId = null): array
    {
        app(ClinicAccessService::class)->assertWritable();

        if ($error = $this->configurationError(Setting::getSettings())) {
            return ['success' => false, 'error' => $error];
        }

        $phone  = PhoneNumber::normalize($to);
        $result = $this->post($phone, ['type' => 'text', 'text' => ['body' => $message]]);

        SmsLog::create([
            'patient_id'          => $patientId,
            'template_key'        => 'test',
            'channel'             => 'whatsapp',
            'recipient'           => $phone,
            'message'             => $message,
            'status'              => $result['success'] ? 'sent' : 'failed',
            'success'             => $result['success'],
            'provider_message_id' => $result['message_id'] ?? null,
            'attempts'            => 1,
            'sent_at'             => $result['success'] ? now() : null,
            'error'               => $result['success'] ? null : $result['error'],
        ]);

        return $result;
    }

    private function configurationError(Setting $s): ?string
    {
        if (empty($s->whatsapp_enabled) || !$s->whatsapp_enabled) {
            return 'WhatsApp notifications are disabled.';
        }

        if (empty($s->whatsapp_phone_number_id) || empty($s->whatsapp_access_token)) {
            return 'WhatsApp not configured. Add credentials in Settings → WhatsApp.';
        }

        return null;
    }

    /** @return array{success: bool, message_id?: ?string, response?: array, error?: string, retryable?: bool} */
    private function post(string $phone, array $message): array
    {
        $s = Setting::getSettings();

        if ($error = $this->configurationError($s)) {
            return ['success' => false, 'error' => $error];
        }

        try {
            $accessToken = Crypt::decryptString($s->whatsapp_access_token);
        } catch (\Exception $e) {
            Log::error('WhatsAppService: failed to decrypt access token.', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'WhatsApp credentials are corrupted. Please re-save them in Settings → WhatsApp.'];
        }

        try {
            $client   = new Client(['timeout' => 15]);
            $response = $client->post(
                self::BASE_URL . '/' . self::API_VERSION . '/' . $s->whatsapp_phone_number_id . '/messages',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => ['messaging_product' => 'whatsapp', 'to' => $phone] + $message,
                ]
            );

            $body      = json_decode((string) $response->getBody(), true) ?? [];
            $messageId = $body['messages'][0]['id'] ?? null;

            return $messageId
                ? ['success' => true, 'message_id' => $messageId, 'response' => $body]
                : ['success' => false, 'error' => json_encode($body)];

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $raw  = (string) $e->getResponse()->getBody();
            $body = json_decode($raw, true);

            return ['success' => false, 'error' => $body['error']['message'] ?? $raw];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'retryable' => true];
        }
    }

    private function optOutReason(?int $patientId, ?string $templateKey): ?string
    {
        $patient = $patientId ? Patient::find($patientId) : null;

        if (!$patient) {
            return null;
        }

        if ($patient->whatsapp_opt_out) {
            return 'Patient has opted out of WhatsApp messages.';
        }

        if ($patient->marketing_opt_out && MessageCategory::for($templateKey) === MessageCategory::MARKETING) {
            return 'Patient has opted out of marketing messages.';
        }

        return null;
    }
}
