<?php

namespace App\Services\Scans;

use App\Mail\OwnerNoticeMail;
use App\Models\AuditTrail;
use App\Models\PatientDocument;
use App\Models\ScanOrder;
use App\Models\Setting;
use App\Services\SmsService;
use App\Services\SubscriptionQuotaService;
use App\Support\Messaging\WhatsAppLink;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Scan results: files attached to the order (kept as the patient's clinical documents, so they
 * also show in the patient's file), and sending them to the referring clinic. Sending needs the
 * patient's consent and goes as a link that works for LINK_DAYS days; nothing is attached to the
 * message itself.
 */
class ScanResultsService
{
    public const LINK_DAYS = 14;

    /** Document types a scan result can be (the patient file's own types). */
    public const TYPES = ['oct' => 'OCT', 'visual_field' => 'Visual field', 'fundus_photo' => 'Fundus photo', 'other' => 'Report / other'];

    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email', 'link' => 'Link copied'];

    public function attach(ScanOrder $order, UploadedFile $file, string $type, ?string $title = null): PatientDocument
    {
        if ($order->status === 'cancelled') throw ValidationException::withMessages(['resultFiles' => 'This order was cancelled.']);
        if (! $order->sale_id) throw ValidationException::withMessages(['resultFiles' => 'Bill the order before adding results.']);
        if (! array_key_exists($type, self::TYPES)) throw ValidationException::withMessages(['resultType' => 'Choose what the file is.']);
        app(SubscriptionQuotaService::class)->assertStorageAvailable(app(TenantContext::class)->requireClinic(), (int) $file->getSize());

        $patient = $order->patient()->firstOrFail();
        $disk = PatientDocument::uploadDisk();
        try {
            $path = $file->store(TenantStorage::patientDocuments($patient), $disk);
        } catch (\Throwable $e) {
            report($e);
            $path = false;
        }
        if (! $path) {
            throw ValidationException::withMessages(['resultFiles' => "{$file->getClientOriginalName()} could not be saved. Try again; if it keeps failing, tell your administrator."]);
        }

        $document = PatientDocument::create([
            'patient_id' => $patient->id, 'consultation_id' => $order->consultation_id, 'scan_order_id' => $order->id,
            'uploaded_by' => Auth::id(), 'document_type' => $type,
            'title' => trim((string) $title) ?: self::TYPES[$type].' result · '.$order->order_number,
            'notes' => null, 'file_path' => $path, 'storage_disk' => $disk, 'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(),
        ]);
        AuditTrail::record('document.uploaded', "Scan result for {$order->order_number}: {$document->title}", $document, [],
            ['document_type' => $type, 'scan_order' => $order->order_number], $patient->id);

        return $document;
    }

    /** The patient agreed later (on the phone, or at collection): results may now be sent. */
    public function recordConsent(ScanOrder $order): void
    {
        if ($order->source !== 'partner') throw ValidationException::withMessages(['send' => 'Only orders from a partner clinic are sent to a referrer.']);
        if ($order->share_consent) return;
        $order->forceFill(['share_consent' => true, 'consent_by' => Auth::id(), 'consent_at' => now()])->save();
        AuditTrail::record('scans.consent', "Scan order {$order->order_number}: patient agreed to share results with ".($order->partner?->name ?? 'the referring clinic'),
            $order, ['share_consent' => false], ['share_consent' => true], $order->patient_id);
    }

    /** The link the referring clinic opens: a page listing the result files, valid for LINK_DAYS days. */
    public function link(ScanOrder $order): string
    {
        return URL::temporarySignedRoute('scan-results.show', now()->addDays(self::LINK_DAYS), ['order' => $order->id]);
    }

    public function message(ScanOrder $order, ?string $link = null): string
    {
        $clinic = Setting::getSettings()->clinic_name ?: (app(TenantContext::class)->clinic()?->name ?? 'Our clinic');

        return "{$clinic}: scan results for ".($order->patient?->name ?? 'your patient')." ({$order->order_number}) — "
            .$order->items->map->label()->implode(', ').'. View them here (link works for '.self::LINK_DAYS.' days): '.($link ?? $this->link($order));
    }

    public function whatsAppLink(ScanOrder $order): ?string
    {
        $phone = $order->partner?->messagingPhone();

        return $phone ? WhatsAppLink::to($phone, $this->message($order)) : null;
    }

    /**
     * Send (or record sending) the results. SMS and email go from here; WhatsApp is sent by staff
     * from their phone and "link" means they copied it, so those are only recorded.
     */
    public function send(ScanOrder $order, string $channel): void
    {
        $order->loadMissing('partner', 'items', 'patient', 'documents');
        if (! array_key_exists($channel, self::CHANNELS)) throw ValidationException::withMessages(['send' => 'Choose how to send the results.']);
        if ($order->source !== 'partner' || ! $order->partner) throw ValidationException::withMessages(['send' => 'Only orders from a partner clinic are sent to a referrer.']);
        if (! $order->share_consent) throw ValidationException::withMessages(['send' => 'The patient has not agreed to share their results with '.$order->partner->name.'.']);
        if (! in_array($order->status, ['reported', 'sent'], true)) throw ValidationException::withMessages(['send' => 'Mark the scans reported before sending results.']);
        if ($order->documents->isEmpty()) throw ValidationException::withMessages(['send' => 'Add the result files first.']);

        $link = $this->link($order);
        if ($channel === 'sms') {
            $phone = $order->partner->messagingPhone();
            if (! $phone) throw ValidationException::withMessages(['send' => $order->partner->name.' has no phone number for messages. Add one under Partner Clinics.']);
            $result = app(SmsService::class)->send($phone, $this->message($order, $link));
            if (! ($result['success'] ?? false)) throw ValidationException::withMessages(['send' => 'The SMS was not sent: '.($result['error'] ?? 'unknown error').'.']);
        }
        if ($channel === 'email') {
            $email = filter_var(trim((string) $order->partner->email), FILTER_VALIDATE_EMAIL);
            if (! $email) throw ValidationException::withMessages(['send' => $order->partner->name.' has no email address. Add one under Partner Clinics.']);
            $this->email($order, $email, $link);
        }

        $order->forceFill(['status' => 'sent', 'results_sent_at' => now(), 'results_sent_via' => $channel, 'results_sent_by' => Auth::id()])->save();
        AuditTrail::record('scans.results_sent', "Scan results for {$order->order_number} sent to {$order->partner->name} by ".self::CHANNELS[$channel]
            ." ({$order->documents->count()} ".($order->documents->count() === 1 ? 'file' : 'files').')', $order, [], ['via' => $channel], $order->patient_id);
    }

    private function email(ScanOrder $order, string $to, string $link): void
    {
        $settings = Setting::getSettings();
        $clinic = $settings->clinic_name ?: (app(TenantContext::class)->clinic()?->name ?? 'Our clinic');
        $mail = new OwnerNoticeMail($clinic, 'Scan results: '.($order->patient?->name ?? 'your patient'),
            "The results of the scans you referred are ready.",
            array_filter(['Patient' => $order->patient?->name, 'Order' => $order->order_number, 'Scans' => $order->items->map->label()->implode(', '),
                'Reported' => $order->reported_at?->format('d M Y')]),
            'View the results', $link, 'The link works for '.self::LINK_DAYS.' days. Ask us for a new one after that.',
            "Sent by {$clinic} to the clinic that referred this patient, who agreed to share their results.");
        $mail->subject("Scan results {$order->order_number} - {$clinic}");
        if ($reply = filter_var(trim((string) $settings->clinic_email), FILTER_VALIDATE_EMAIL)) $mail->replyTo($reply);
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            Log::warning('Scan results email failed.', ['order' => $order->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['send' => 'The email could not be sent. Check the email settings, or send by WhatsApp instead.']);
        }
    }
}
