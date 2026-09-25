<?php

namespace App\Services\Messaging;

use App\Models\Setting;
use App\Services\ClinicAccessService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SmsCredentialResolver
{
    /**
     * Hosted clinics always send through the platform gateway account and may only use
     * a sender ID the platform has approved. Offline/desktop installs cannot hold the
     * platform key, so they keep the credentials saved in their own settings.
     *
     * @throws SmsNotConfiguredException
     */
    public function resolve(?Setting $settings = null): SmsCredentials
    {
        $settings ??= Setting::getSettings();

        if (app(ClinicAccessService::class)->hosted()) {
            return $this->platform($settings);
        }

        if (empty($settings->sms_api_url) || empty($settings->sms_api_key)) {
            throw new SmsNotConfiguredException('SMS not configured. Add credentials in Settings → SMS Settings.');
        }

        try {
            $key = Crypt::decryptString($settings->sms_api_key);
        } catch (\Exception $e) {
            Log::error('SmsCredentialResolver: failed to decrypt API key. Re-save credentials in Settings → SMS Settings.', ['error' => $e->getMessage()]);
            throw new SmsNotConfiguredException('SMS credentials are corrupted. Please re-save them in Settings → SMS Settings.');
        }

        return new SmsCredentials($settings->sms_api_url, $key, $settings->sms_sender_id, false);
    }

    /** @throws SmsNotConfiguredException */
    public function platform(?Setting $settings = null): SmsCredentials
    {
        $url = config('services.eazisms.url');
        $key = config('services.eazisms.key');

        if (empty($url) || empty($key)) {
            throw new SmsNotConfiguredException('The platform SMS gateway is not configured. Contact the platform administrator.');
        }

        // For hosted clinics sms_sender_id is only ever written by a platform approval
        // (a newer pending request does not disable the sender already approved).
        $sender = filled($settings?->sms_sender_id) ? $settings->sms_sender_id : config('services.eazisms.default_sender');

        return new SmsCredentials($url, $key, $sender, true);
    }
}
