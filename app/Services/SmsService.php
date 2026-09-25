<?php

namespace App\Services;

use App\Jobs\SendSmsMessage;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\Messaging\InsufficientSmsCreditsException;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Messaging\SmsCreditService;
use App\Services\Messaging\SmsCredentialResolver;
use App\Services\Messaging\SmsDriver;
use App\Services\Messaging\SmsNotConfiguredException;
use App\Support\Messaging\MessageCategory;
use App\Support\Messaging\PhoneNumber;
use App\Support\Messaging\SmsSegments;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

class SmsService
{
    /**
     * Validate, meter and log an SMS, then hand it to the queue (hosted clinics) or
     * deliver it inline (offline installs, which usually run no queue worker).
     *
     * Returns ['success' => true,  'queued' => bool, 'log_id' => int]
     *      or ['success' => false, 'error' => '...'] (plus 'skipped' => true for opt-outs)
     */
    public function send(string $to, string $message, ?int $patientId = null, ?string $templateKey = null): array
    {
        return $this->accept($to, $message, $patientId, $templateKey, false);
    }

    /** Deliver immediately and report the gateway result (used by the settings "test send"). */
    public function sendNow(string $to, string $message, ?int $patientId = null, ?string $templateKey = null): array
    {
        return $this->accept($to, $message, $patientId, $templateKey, true);
    }

    /**
     * Called by SendSmsMessage (or inline) to push a logged message through the gateway.
     * Credits are returned when the message fails for good; a retryable failure keeps them
     * until the queue gives up ($finalAttempt).
     */
    public function deliver(SmsLog $log, bool $finalAttempt = true): array
    {
        try {
            $credentials = app(SmsCredentialResolver::class)->resolve();
        } catch (SmsNotConfiguredException $e) {
            $log->update(['status' => 'failed', 'success' => false, 'error' => $e->getMessage()]);
            app(SmsCreditService::class)->refund($log);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $result = app(SmsDriver::class)->send($credentials, $log->recipient, $log->message);

        $log->update([
            'attempts'            => $log->attempts + 1,
            'sender_id'           => $credentials->sender,
            'status'              => $result['success'] ? 'sent' : 'failed',
            'success'             => $result['success'],
            'provider_message_id' => $result['message_id'] ?? null,
            'sent_at'             => $result['success'] ? now() : null,
            'error'               => $result['success'] ? null : ($result['error'] ?? 'Unknown gateway error.'),
        ]);

        if (!$result['success'] && ($finalAttempt || !($result['retryable'] ?? false))) {
            app(SmsCreditService::class)->refund($log);
        }

        return $result['success']
            ? ['success' => true, 'response' => $result['response'] ?? []]
            : ['success' => false, 'error' => $log->error, 'retryable' => $result['retryable'] ?? false];
    }

    /** Check the gateway balance. Hosted clinics use prepaid platform credits instead. */
    public function checkBalance(): array
    {
        if (app(ClinicAccessService::class)->hosted()) {
            return ['success' => false, 'error' => 'SMS credits are bought from the platform. Your credit balance is shown above.'];
        }

        try {
            $credentials = app(SmsCredentialResolver::class)->resolve();
        } catch (SmsNotConfiguredException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return app(SmsDriver::class)->balance($credentials);
    }

    private function accept(string $to, string $message, ?int $patientId, ?string $templateKey, bool $immediate): array
    {
        app(ClinicAccessService::class)->assertWritable();
        $s = Setting::getSettings();

        if (isset($s->sms_enabled) && !$s->sms_enabled) {
            return ['success' => false, 'error' => 'SMS notifications are currently paused.'];
        }

        // Surface configuration problems to the caller now rather than failing silently in a worker.
        try {
            $credentials = app(SmsCredentialResolver::class)->resolve($s);
        } catch (SmsNotConfiguredException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $phone    = PhoneNumber::normalize($to);
        $segments = SmsSegments::count($message);
        $log      = [
            'patient_id'   => $patientId,
            'template_key' => $templateKey,
            'channel'      => 'sms',
            'recipient'    => $phone,
            'message'      => $message,
            'segments'     => $segments,
        ];

        if ($reason = $this->optOutReason($patientId, $templateKey)) {
            SmsLog::create($log + ['status' => 'skipped', 'success' => false, 'error' => $reason]);
            return ['success' => false, 'skipped' => true, 'error' => $reason];
        }

        $log += ['status' => 'queued', 'success' => false];

        if ($credentials->platformManaged) {
            // Hosted clinics pay for platform SMS with prepaid credits, taken under a wallet lock.
            $clinicId = SmsLog::clinicIdForWrite();
            try {
                $log = app(SmsCreditService::class)->charge($clinicId, $segments, fn () => SmsLog::create($log));
            } catch (InsufficientSmsCreditsException $e) {
                $this->notifyCreditsExhausted($clinicId);
                return ['success' => false, 'error' => $e->getMessage()];
            }
        } else {
            $log = SmsLog::create($log);
        }

        if ($immediate || !app(MessageDispatcher::class)->queues()) {
            return $this->deliver($log) + ['queued' => false, 'log_id' => $log->id];
        }

        app(MessageDispatcher::class)->dispatch(new SendSmsMessage($log->id));

        return ['success' => true, 'queued' => true, 'log_id' => $log->id];
    }

    /** Tell clinic admins once a day (per branch) that sends are being refused, instead of once per patient. */
    private function notifyCreditsExhausted(int $clinicId): void
    {
        $branchId = app(TenantContext::class)->branchId();
        if (!Cache::add("sms-credits-exhausted:{$clinicId}:{$branchId}:" . now()->toDateString(), true, now()->endOfDay())) {
            return;
        }

        NotificationService::sendToRoles(
            ['Super Admin'],
            'sms_credits_exhausted',
            'Out of SMS credits',
            'SMS messages are not being sent because the clinic has run out of SMS credits. Buy a bundle in Settings → SMS; WhatsApp links and email are unaffected.',
            'fas fa-sms',
            'text-danger',
            route('admin.settings', ['tab' => 'sms'], absolute: false)
        );
    }

    private function optOutReason(?int $patientId, ?string $templateKey): ?string
    {
        $patient = $patientId ? Patient::find($patientId) : null;

        if (!$patient) {
            return null;
        }

        if ($patient->sms_opt_out) {
            return 'Patient has opted out of SMS messages.';
        }

        if ($patient->marketing_opt_out && MessageCategory::for($templateKey) === MessageCategory::MARKETING) {
            return 'Patient has opted out of marketing messages.';
        }

        return null;
    }
}
