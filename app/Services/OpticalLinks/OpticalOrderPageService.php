<?php

namespace App\Services\OpticalLinks;

use App\Models\AuditTrail;
use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerClinic;
use App\Models\User;
use App\Services\ClinicAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ordering page for a partner clinic that is not on Nexus (decided 2026-11-18).
 *
 * The optical centre turns it on for a partner: a private link plus a 6-digit PIN it sets and
 * passes on. Whoever opens the link must enter the PIN (5 wrong tries lock it for 15 minutes).
 * The partner sends spectacle orders (wearer, phone, typed prescription) and follows them; the
 * optical centre accepts them in Incoming Orders like orders from linked clinics, and prices
 * them as ordinary partner jobs (no quote approval on the page). If the partner later links on
 * Nexus, its requests move to its account and the page closes (OpticalLinkService::acceptInvite).
 */
class OpticalOrderPageService
{
    public const MAX_TRIES = 5;
    public const LOCK_MINUTES = 15;
    /** A signed-in page stays open this long without use. */
    public const SESSION_MINUTES = 240;

    /* ---------------------------------------------------------------- optical side (managers) */

    /** Turn the page on (or set a new PIN). Returns the PIN, which is shown once. */
    public function enable(OpticalPartnerClinic $partner, User $by, ?string $pin = null): string
    {
        $this->assertManager($by);
        app(ClinicAccessService::class)->assertWritable();
        $partner = OpticalPartnerClinic::findOrFail($partner->id);
        if ($partner->hasLink()) throw ValidationException::withMessages(['page' => 'This partner is linked on Nexus, so it sends orders from its own account.']);
        if (! $partner->is_active) throw ValidationException::withMessages(['page' => 'Restore this partner clinic before turning on its ordering page.']);
        $pin = trim((string) $pin);
        if ($pin === '') $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (! preg_match('/^\d{6}$/', $pin)) throw ValidationException::withMessages(['pagePin' => 'The PIN is 6 digits.']);
        if (in_array($pin, ['000000', '111111', '123456', '654321', '121212', '999999'], true)) {
            throw ValidationException::withMessages(['pagePin' => 'Choose a PIN that is harder to guess.']);
        }

        $fresh = ! $partner->page_token;
        $partner->forceFill([
            'page_token' => $partner->page_token ?: $this->newToken(),
            'page_pin_hash' => Hash::make($pin), 'page_pin_set_at' => now(),
            'page_failed_attempts' => 0, 'page_locked_until' => null,
        ])->save();
        AuditTrail::record($fresh ? 'optical.order_page_on' : 'optical.order_page_pin', ($fresh ? 'Turned on the ordering page for ' : 'Set a new ordering-page PIN for ').$partner->name, $partner);

        return $pin;
    }

    /** A new link: the old one stops working (e.g. it was shared too widely). The PIN stays. */
    public function newLink(OpticalPartnerClinic $partner, User $by): void
    {
        $this->assertManager($by);
        $partner = OpticalPartnerClinic::findOrFail($partner->id);
        abort_unless($partner->page_token, 422);
        $partner->forceFill(['page_token' => $this->newToken()])->save();
        AuditTrail::record('optical.order_page_link', 'Made a new ordering-page link for '.$partner->name.'; the old one no longer works', $partner);
    }

    public function disable(OpticalPartnerClinic $partner, User $by): void
    {
        $this->assertManager($by);
        $partner = OpticalPartnerClinic::findOrFail($partner->id);
        $partner->forceFill(['page_token' => null, 'page_pin_hash' => null, 'page_failed_attempts' => 0, 'page_locked_until' => null])->save();
        AuditTrail::record('optical.order_page_off', 'Turned off the ordering page for '.$partner->name, $partner);
    }

    /* ---------------------------------------------------------------- the page (no account) */

    /** The partner a link belongs to, while its page is on and the optical centre can take orders. */
    public function partnerForToken(string $token): ?OpticalPartnerClinic
    {
        if (strlen($token) < 32) return null;
        $partner = OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')->where('page_token', $token)->first();
        if (! $partner || ! hash_equals((string) $partner->page_token, $token)) return null;
        if (! $partner->page_pin_hash || ! $partner->is_active || $partner->hasLink()) return null;
        if (! $partner->clinic || $partner->clinic->status !== 'active') return null;

        return $partner;
    }

    public function canTakeOrders(OpticalPartnerClinic $partner): bool
    {
        return (bool) ($partner->clinic && app(OpticalLinkService::class)->opticalCanReceive($partner->clinic));
    }

    /** Check the PIN; count wrong tries and lock the page for a while after too many. */
    public function checkPin(OpticalPartnerClinic $partner, string $pin): void
    {
        // The transaction returns the error instead of throwing it, so a wrong try is still counted.
        $error = DB::transaction(function () use ($partner, $pin) {
            $partner = OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')->lockForUpdate()->findOrFail($partner->id);
            if ($partner->page_locked_until && $partner->page_locked_until->isFuture()) {
                return 'Too many wrong PINs. Try again after '.$partner->page_locked_until->format('H:i').', or ask '.($partner->clinic?->name ?? 'the optical centre').' for the PIN.';
            }
            if (Hash::check(trim($pin), (string) $partner->page_pin_hash)) {
                $partner->forceFill(['page_failed_attempts' => 0, 'page_locked_until' => null, 'page_last_used_at' => now()])->save();
                return null;
            }
            $tries = (int) $partner->page_failed_attempts + 1;
            $locked = $tries >= self::MAX_TRIES;
            $partner->forceFill(['page_failed_attempts' => $locked ? 0 : $tries, 'page_locked_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : null])->save();

            return $locked
                ? 'Too many wrong PINs. The page is locked for '.self::LOCK_MINUTES.' minutes.'
                : 'That PIN is not right. '.(self::MAX_TRIES - $tries).' '.Str::plural('try', self::MAX_TRIES - $tries).' left.';
        });
        if ($error) throw ValidationException::withMessages(['pin' => $error]);
    }

    /** @return Collection<int, OpticalOrderRequest> */
    public function requests(OpticalPartnerClinic $partner): Collection
    {
        return OpticalOrderRequest::where('optical_partner_clinic_id', $partner->id)->whereNull('sender_clinic_id')
            ->latest('id')->limit(200)->get();
    }

    public function request(OpticalPartnerClinic $partner, int $id): OpticalOrderRequest
    {
        return OpticalOrderRequest::where('optical_partner_clinic_id', $partner->id)->whereNull('sender_clinic_id')->findOrFail($id);
    }

    /** @param array<string, mixed> $data validated by the controller */
    public function send(OpticalPartnerClinic $partner, array $data): OpticalOrderRequest
    {
        if (! $this->canTakeOrders($partner)) {
            throw ValidationException::withMessages(['patient_name' => ($partner->clinic?->name ?? 'The optical centre').' is not taking orders online right now. Please contact them directly.']);
        }
        $rx = ['od' => [], 'os' => [], 'pd' => $data['pd'] ?? null];
        foreach (['od', 'os'] as $eye) {
            foreach (['sph', 'cyl', 'axis', 'add'] as $field) {
                $value = $data[$eye.'_'.$field] ?? null;
                $rx[$eye][$field] = $value === null || $value === '' ? null : (float) $value;
            }
        }

        return OpticalOrderRequest::create([
            'sender_clinic_id' => null, 'source' => 'page', 'sender_name' => $this->clean($data['sender_name'], 120),
            'optical_clinic_id' => $partner->clinic_id, 'optical_partner_clinic_id' => $partner->id,
            'sender_reference' => $this->clean($data['reference'] ?? null, 100),
            'patient_name' => $this->clean($data['patient_name'], 150), 'patient_phone' => $this->clean($data['patient_phone'] ?? null, 50),
            'rx' => $rx, 'lens_type' => $this->clean($data['lens_type'] ?? null, 60), 'lens_notes' => $this->clean($data['lens_notes'] ?? null, 1000),
            'frame_choice' => $data['frame_choice'], 'frame_notes' => $this->clean($data['frame_notes'] ?? null, 1000),
            'urgency' => ($data['urgency'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal', 'notes' => $this->clean($data['notes'] ?? null, 2000),
            'status' => 'sent', 'status_updated_at' => now(),
        ]);
    }

    public function answer(OpticalPartnerClinic $partner, int $id, string $answer): void
    {
        $request = $this->request($partner, $id);
        abort_unless($request->status === 'query', 422);
        $answer = $this->clean($answer, 2000);
        if (! $answer) throw ValidationException::withMessages(['answer' => 'Type your answer.']);
        $request->forceFill(['status' => 'sent', 'query_answer' => $answer, 'status_updated_at' => now()])->save();
    }

    public function cancel(OpticalPartnerClinic $partner, int $id): void
    {
        $request = $this->request($partner, $id);
        abort_unless($request->isOpen(), 422, 'The optical centre has already accepted this order. Call them to change it.');
        $request->forceFill(['status' => 'cancelled', 'status_updated_at' => now()])->save();
    }

    /* ---------------------------------------------------------------- joining Nexus later */

    /** The partner linked on Nexus: its page orders move to its account and the page closes. */
    public static function carryOver(OpticalPartnerClinic $partner, int $clinicId): int
    {
        $moved = OpticalOrderRequest::where('optical_partner_clinic_id', $partner->id)->whereNull('sender_clinic_id')
            ->update(['sender_clinic_id' => $clinicId]);
        $partner->forceFill(['page_token' => null, 'page_pin_hash' => null, 'page_failed_attempts' => 0, 'page_locked_until' => null])->save();

        return $moved;
    }

    private function assertManager(User $by): void
    {
        abort_unless($by->hasAnyRole(['Super Admin', 'Manager']), 403);
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(40);
        } while (OpticalPartnerClinic::withoutGlobalScopes()->where('page_token', $token)->exists());

        return $token;
    }

    private function clean(?string $text, int $max): ?string
    {
        $text = trim(strip_tags((string) $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
