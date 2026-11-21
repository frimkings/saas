<?php

namespace App\Services\OpticalLinks;

use App\Models\AuditTrail;
use App\Models\Clinic;
use App\Models\LensOrder;
use App\Models\OpticalLinkInvite;
use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerClinic;
use App\Models\Refractions;
use App\Models\User;
use App\Services\ClinicAccessService;
use App\Services\SubscriptionService;
use App\Support\Feature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Links between optical centres and the clinics that send them spectacle orders.
 *
 * The optical centre invites from a partner record; the clinic's owner or manager accepts with the
 * code. A linked clinic sends a request (wearer name, phone and Rx only) that the optical centre
 * accepts into an ordinary partner order. Nothing here writes into the other clinic's own tables:
 * invites and requests are shared rows, and each side copies what it needs.
 */
class OpticalLinkService
{
    public const INVITE_DAYS = 7;

    /* ---------------------------------------------------------------- invites (optical side) */

    public function createInvite(OpticalPartnerClinic $partner, User $by): OpticalLinkInvite
    {
        $clinicId = $this->currentClinicId();
        abort_unless((int) $partner->clinic_id === $clinicId, 404);
        if ($partner->is_active === false) {
            throw ValidationException::withMessages(['invite' => 'Restore this partner clinic before inviting it to link.']);
        }
        if ($partner->hasLink()) {
            throw ValidationException::withMessages(['invite' => 'This partner is already linked to a clinic.']);
        }

        return DB::transaction(function () use ($partner, $by, $clinicId) {
            // One open invite per partner: a new one replaces the old.
            OpticalLinkInvite::where('optical_partner_clinic_id', $partner->id)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $invite = OpticalLinkInvite::create([
                'optical_clinic_id' => $clinicId, 'optical_partner_clinic_id' => $partner->id, 'code' => $this->newCode(),
                'created_by' => $by->id, 'expires_at' => now()->addDays(self::INVITE_DAYS),
            ]);
            AuditTrail::record('optical.link_invited', 'Invited partner clinic '.$partner->name.' to link', $partner);

            return $invite;
        });
    }

    public function openInvite(OpticalPartnerClinic $partner): ?OpticalLinkInvite
    {
        return OpticalLinkInvite::where('optical_partner_clinic_id', $partner->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->latest('id')->first();
    }

    public function revokeInvite(OpticalPartnerClinic $partner): void
    {
        abort_unless((int) $partner->clinic_id === $this->currentClinicId(), 404);
        OpticalLinkInvite::where('optical_partner_clinic_id', $partner->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /* ---------------------------------------------------------------- accepting (clinic side) */

    /** The invite a code names, if it can still be accepted by the current clinic. */
    public function findInvite(string $code): OpticalLinkInvite
    {
        $invite = OpticalLinkInvite::with('opticalClinic')->where('code', strtoupper(trim($code)))->first();
        if (! $invite || ! $invite->isOpen()) {
            throw ValidationException::withMessages(['code' => 'This invite code is not valid or has expired. Ask the optical centre for a new one.']);
        }
        if ((int) $invite->optical_clinic_id === $this->currentClinicId()) {
            throw ValidationException::withMessages(['code' => 'This invite is from your own clinic. Open it while signed in to the clinic that sends the orders.']);
        }

        return $invite;
    }

    public function acceptInvite(string $code, User $by): OpticalPartnerClinic
    {
        abort_unless($this->canManageLinks($by), 403);
        app(ClinicAccessService::class)->assertWritable();
        $clinicId = $this->currentClinicId();

        return DB::transaction(function () use ($code, $by, $clinicId) {
            $invite = $this->findInvite($code);
            $invite = OpticalLinkInvite::whereKey($invite->id)->lockForUpdate()->first();
            $already = OpticalPartnerClinic::withoutGlobalScopes()->where('clinic_id', $invite->optical_clinic_id)
                ->where('linked_clinic_id', $clinicId)->whereIn('link_status', ['active', 'paused'])->exists();
            if ($already) {
                throw ValidationException::withMessages(['code' => 'Your clinic is already linked to this optical centre.']);
            }
            $partner = OpticalPartnerClinic::withoutGlobalScopes()->findOrFail($invite->optical_partner_clinic_id);
            if ($partner->hasLink()) {
                throw ValidationException::withMessages(['code' => 'This invite has already been used by another clinic.']);
            }
            // An earlier, ended link to the same clinic frees its slot in the unique index.
            OpticalPartnerClinic::withoutGlobalScopes()->where('clinic_id', $invite->optical_clinic_id)
                ->where('linked_clinic_id', $clinicId)->where('id', '!=', $partner->id)->update(['linked_clinic_id' => null]);
            $partner->forceFill(['linked_clinic_id' => $clinicId, 'link_status' => 'active', 'linked_at' => now(), 'link_paused_by_clinic_id' => null])->save();
            $invite->forceFill(['accepted_clinic_id' => $clinicId, 'accepted_by' => $by->id, 'accepted_at' => now()])->save();
            // Orders it sent from its ordering page before joining move to its account; the page closes.
            OpticalOrderPageService::carryOver($partner, $clinicId);
            AuditTrail::record('optical.link_accepted', 'Linked to optical centre '.$invite->opticalClinic?->name, null, [], ['optical_clinic_id' => $invite->optical_clinic_id]);

            return $partner;
        });
    }

    /* ---------------------------------------------------------------- pause / unlink (either side) */

    /** Links the current clinic takes part in, as the sender (clinic side). */
    public function linksForSender(?int $clinicId = null): Collection
    {
        // A single install (no clinic context) has nobody to link with.
        $clinicId ??= app(TenantContext::class)->clinicId();
        if (! $clinicId) return collect();

        return OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')
            ->where('linked_clinic_id', $clinicId)
            ->whereIn('link_status', ['active', 'paused'])->orderBy('id')->get();
    }

    public function setLinkStatus(OpticalPartnerClinic $partner, string $status, User $by): void
    {
        abort_unless(in_array($status, ['active', 'paused', 'ended'], true), 422);
        abort_unless($this->canManageLinks($by), 403);
        $clinicId = $this->currentClinicId();
        $partner = OpticalPartnerClinic::withoutGlobalScopes()->findOrFail($partner->id);
        abort_unless(in_array($clinicId, [(int) $partner->clinic_id, (int) $partner->linked_clinic_id], true), 404);
        abort_unless($partner->hasLink(), 422);
        app(ClinicAccessService::class)->assertWritable();
        if ($partner->link_paused_by_platform && $status !== 'ended') {
            throw ValidationException::withMessages(['link' => 'Nexus support paused this link'.($partner->link_status_reason ? ': '.$partner->link_status_reason : '').'. Contact support to resume it.']);
        }

        $old = $partner->link_status;
        if ($status === 'active' && $old === 'paused' && $partner->link_paused_by_clinic_id && (int) $partner->link_paused_by_clinic_id !== $clinicId) {
            throw ValidationException::withMessages(['link' => 'The other side paused this link, so only they can resume it.']);
        }
        // Unlinking keeps linked_clinic_id so history still shows who it was; the slot frees on a new link.
        $partner->forceFill(['link_status' => $status, 'link_paused_by_clinic_id' => $status === 'paused' ? $clinicId : null])->save();
        AuditTrail::record('optical.link_'.$status, 'Optical link '.$partner->name.': '.$old.' → '.$status, null, ['link_status' => $old], ['link_status' => $status, 'partner_id' => $partner->id]);
    }

    /* ---------------------------------------------------------------- sending (clinic side) */

    /** Linked optical centres this clinic can send to now. */
    public function sendTargets(): Collection
    {
        return $this->linksForSender()->filter(fn ($p) => $p->link_status === 'active' && $p->is_active
            && $p->clinic && $this->opticalCanReceive($p->clinic))->values();
    }

    public function send(Refractions $refraction, int $partnerId, array $options, User $by): OpticalOrderRequest
    {
        $clinicId = $this->currentClinicId();
        app(ClinicAccessService::class)->assertWritable();
        abort_unless($refraction->dispensing_required && $refraction->dispensing_authorized_at, 403);
        $patient = $refraction->consultation?->patient;
        abort_unless($patient, 404);
        $partner = $this->sendTargets()->firstWhere('id', $partnerId);
        if (! $partner) {
            throw ValidationException::withMessages(['partnerId' => 'Choose a linked optical centre that is taking orders.']);
        }
        if ($label = $refraction->activeOrderLabel()) {
            throw ValidationException::withMessages(['partnerId' => 'This prescription '.$label.'.']);
        }
        $owed = OpticalLinkTermsService::owed($partner);
        if (OpticalLinkTermsService::overLimit($partner, $owed)) {
            throw ValidationException::withMessages(['partnerId' => OpticalLinkTermsService::creditMessage($partner, $owed)]);
        }
        if ($this->openRequestFor($refraction->id)) {
            throw ValidationException::withMessages(['partnerId' => 'This refraction has already been sent and is still open.']);
        }
        $frameChoice = $options['frame_choice'] ?? 'patient_chooses';
        abort_unless(array_key_exists($frameChoice, OpticalOrderRequest::FRAME_CHOICES), 422);

        $request = OpticalOrderRequest::create([
            'sender_clinic_id' => $clinicId,
            'sender_branch_id' => app(TenantContext::class)->branchId(),
            'sent_by' => $by->id,
            'optical_clinic_id' => $partner->clinic_id,
            'optical_partner_clinic_id' => $partner->id,
            'refraction_id' => $refraction->id,
            'sender_reference' => $patient->pxnumber,
            'patient_name' => $patient->name,
            'patient_phone' => $patient->contact,
            'rx' => self::rxFromRefraction($refraction),
            'lens_type' => $refraction->lensType,
            'lens_notes' => $this->clean($options['lens_notes'] ?? null),
            'frame_choice' => $frameChoice,
            'frame_notes' => $this->clean($options['frame_notes'] ?? null),
            'urgency' => ($options['urgency'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal',
            'notes' => $this->clean($options['notes'] ?? null),
            'status' => 'sent',
            'status_updated_at' => now(),
        ]);
        AuditTrail::record('optical.request_sent', 'Sent spectacle order for '.$patient->name.' to '.$partner->clinic?->name, null, [], ['request_id' => $request->id], $patient->id);

        return $request;
    }

    public function openRequestFor(int $refractionId): ?OpticalOrderRequest
    {
        // A job the optical centre has cancelled no longer holds the patient.
        return OpticalOrderRequest::forSender($this->currentClinicId())->where('refraction_id', $refractionId)
            ->where('kind', 'order')->whereIn('status', OpticalOrderRequest::ACTIVE)->whereNull('order_cancelled_at')->latest('id')->first();
    }

    public function cancel(OpticalOrderRequest $request, User $by): void
    {
        $request = $this->senderRequest($request->id);
        abort_unless($request->isOpen(), 422, 'Only a request the optical centre has not accepted can be cancelled.');
        $request->forceFill(['status' => 'cancelled', 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
    }

    public function answerQuery(OpticalOrderRequest $request, string $answer, User $by): void
    {
        $request = $this->senderRequest($request->id);
        abort_unless($request->status === 'query', 422);
        $answer = $this->clean($answer);
        if (! $answer) throw ValidationException::withMessages(['answer' => 'Type your answer.']);
        $request->forceFill(['status' => 'sent', 'query_answer' => $answer, 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
    }

    /* ---------------------------------------------------------------- quotes (clinic side) */

    public function approveQuote(OpticalOrderRequest $request, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable();
        DB::transaction(function () use ($request, $by) {
            $request = OpticalOrderRequest::forSender($this->currentClinicId())->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->status === 'quoted', 422, 'This quote is no longer waiting for approval.');
            if ($request->quoteExpired()) {
                throw ValidationException::withMessages(['quote' => 'This quote expired on '.$request->quote_valid_until->format('d M Y').'. Ask the optical centre for a new one.']);
            }
            $request->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id, 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
            AuditTrail::record('optical.quote_approved', 'Approved quote of '.currency().' '.number_format((float) $request->quote_total, 2).' for '.$request->patient_name,
                null, [], ['request_id' => $request->id, 'quote_total' => (float) $request->quote_total]);
        });
    }

    public function declineQuote(OpticalOrderRequest $request, string $reason, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable();
        $request = $this->senderRequest($request->id);
        abort_unless($request->status === 'quoted', 422, 'This quote is no longer waiting for approval.');
        $reason = $this->clean($reason);
        if (! $reason) throw ValidationException::withMessages(['declineReason' => 'Say why, so the optical centre knows.']);
        $request->forceFill(['status' => 'quote_declined', 'decline_reason' => $reason, 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
        AuditTrail::record('optical.quote_declined', 'Declined quote for '.$request->patient_name.': '.$reason, null, [], ['request_id' => $request->id]);
    }

    /** An expired quote goes back to the optical centre to price again. */
    public function askNewQuote(OpticalOrderRequest $request, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable();
        $request = $this->senderRequest($request->id);
        abort_unless($request->quoteExpired(), 422, 'Only an expired quote can be sent back for a new price.');
        $request->forceFill(['status' => 'sent', 'query_answer' => 'The quote expired. Please send a new one.', 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
    }

    /* ---------------------------------------------------------------- cancelling a started job */

    /** The clinic asks the optical centre to cancel a job it approved or started; the centre answers. */
    public function askCancel(OpticalOrderRequest $request, string $reason, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable();
        $request = $this->senderRequest($request->id);
        abort_unless($request->canAskCancel(), 422, 'This job can no longer be cancelled from here.');
        $reason = $this->clean($reason);
        if (! $reason) throw ValidationException::withMessages(['cancelReason' => 'Say why, so the optical centre can decide.']);
        $request->forceFill(['cancel_requested_at' => now(), 'cancel_request_reason' => $reason, 'cancel_requested_by' => $by->id,
            'cancel_refused_reason' => null, 'cancel_answered_at' => null, 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
        AuditTrail::record('optical.cancel_asked', 'Asked the optical centre to cancel job '.($request->order_number ?: '#'.$request->id).' for '.$request->patient_name.': '.$reason,
            null, [], ['request_id' => $request->id]);
    }

    /**
     * The optical centre cancels the job the clinic asked to cancel: its order is cancelled (stock back, nothing
     * billed) and the patient is back in the clinic's To order list. With $charge, work already done is paid for:
     * the job is stopped but counts as finished, its full price stays on the clinic's account (21 Nov 2026).
     */
    public function acceptCancel(OpticalOrderRequest $request, User $by, bool $charge = false): void
    {
        $request = $this->incomingRequest($request->id);
        abort_unless($request->cancelPending() && $request->lens_order_id, 422, 'There is no cancel request waiting on this job.');
        if ($charge) {
            $this->stopAndCharge($request, $by);
            return;
        }
        // The usual cancel: refuses a collected job or one with payments still on it (refund those first).
        app(\App\Services\OpticalOrderWorkflowService::class)->cancel((int) $request->lens_order_id);
        $request->refresh()->forceFill(['status' => 'cancelled', 'cancel_answered_at' => now(), 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
        AuditTrail::record('optical.cancel_accepted', 'Cancelled job '.$request->order_number.' for '.$request->senderLabel().' at their request',
            null, [], ['request_id' => $request->id]);
    }

    /**
     * Stop the job and charge it as finished: no more lab work, nothing goes back to stock (it was used for this
     * patient), no warranty, and the order's full price stays on the clinic's account like any finished job.
     */
    private function stopAndCharge(OpticalOrderRequest $request, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        DB::transaction(function () use ($request, $by) {
            $order = LensOrder::lockForUpdate()->findOrFail((int) $request->lens_order_id);
            if (in_array($order->status, ['Quotation', 'Cancelled', 'Collected'], true)) {
                throw ValidationException::withMessages(['order' => 'Only a job that has started can be stopped and charged.']);
            }
            $was = $order->status;
            $reason = 'Stopped at '.$request->senderLabel()."'s request (".$request->cancel_request_reason.'); charged as a finished job for the work done.';
            $order->forceFill(['status' => 'Collected', 'status_changed_at' => now(), 'collected_at' => now(), 'cancellation_reason' => $reason])->save();
            $request->refresh()->forceFill(['cancel_answered_at' => now(), 'cancel_charged_at' => now(), 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
            AuditTrail::record('optical.cancel_charged', 'Stopped job '.$order->order_id.' for '.$request->senderLabel().' at their request and charged '
                .currency().' '.number_format((float) $order->total, 2).' for the work done', $order, ['status' => $was], ['status' => 'Collected', 'charged' => (float) $order->total], null, true);
        });
    }

    /* ---------------------------------------------------------------- patient lookup (21 Nov 2026) */

    /** Clinic owner: let this optical centre look up our patients, or stop it. Off until turned on. */
    public function setPatientLookup(int $partnerId, bool $on, User $by): OpticalPartnerClinic
    {
        abort_unless(self::isOwner($by), 403, 'Only the clinic owner can change this.');
        app(ClinicAccessService::class)->assertWritable();
        $partner = $this->linksForSender()->firstWhere('id', $partnerId);
        abort_unless($partner, 404);
        $partner->forceFill(['patient_lookup_at' => $on ? now() : null, 'patient_lookup_by' => $on ? $by->id : null])->save();
        AuditTrail::record('optical.patient_lookup_'.($on ? 'on' : 'off'), ($on ? 'Let ' : 'Stopped ').$partner->clinic?->name.($on ? ' look up our patients by phone or patient number' : ' looking up our patients'),
            null, [], ['partner_id' => $partner->id], null, true);

        return $partner;
    }

    public static function isOwner(?User $user): bool
    {
        return $user !== null && $user->getRoleNames()->contains(\App\Support\Access\RoleMap::OWNER);
    }

    /** Optical side: the linked partners whose clinic lets us look up patients. */
    public function lookupPartners(): Collection
    {
        return OpticalPartnerClinic::where('is_active', true)->whereNotNull('patient_lookup_at')->orderBy('name')->get()
            ->filter(fn (OpticalPartnerClinic $partner) => $partner->allowsPatientLookup())->values();
    }

    /**
     * Optical side: find a linked clinic's patient by full phone number or patient number (exact, never a list to
     * browse) with their latest prescription authorised for glasses. Each lookup goes in the clinic's audit trail.
     *
     * @return list<array<string, mixed>>
     */
    public function lookupPatients(int $partnerId, string $query, User $by): array
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $partner = $this->lookupPartner($partnerId);
        $query = trim($query);
        $digits = preg_replace('/\D+/', '', $query);
        if (mb_strlen($query) < 4) throw ValidationException::withMessages(['lookupQuery' => 'Enter the full phone number or patient number.']);

        $patients = \App\Models\Patient::withoutGlobalScopes()->where('clinic_id', $partner->linked_clinic_id)
            ->where(function ($where) use ($query, $digits) {
                $where->where('pxnumber', $query);
                if (strlen($digits) >= 9) {
                    // The same number written as 024…, +23324… or 23324…: compare the last nine digits.
                    $where->orWhereRaw("RIGHT(REGEXP_REPLACE(COALESCE(contact, ''), '[^0-9]', ''), 9) = ?", [substr($digits, -9)]);
                }
            })->orderBy('name')->limit(5)->get();

        $results = $patients->map(function ($patient) use ($partner) {
            $refraction = Refractions::withoutGlobalScopes()->where('clinic_id', $partner->linked_clinic_id)
                ->where('dispensing_required', true)->whereNotNull('dispensing_authorized_at')
                ->whereIn('consultation_id', \App\Models\Consultations::withoutGlobalScopes()->where('patient_id', $patient->id)->select('id'))
                ->latest('dispensing_authorized_at')->first();
            $doctorId = $refraction ? \App\Models\Consultations::withoutGlobalScopes()->whereKey($refraction->consultation_id)->value('user_id') : null;

            return [
                'patient' => $patient->name, 'number' => $patient->pxnumber, 'phone' => $patient->contact,
                'refraction_id' => $refraction?->id, 'date' => $refraction?->dispensing_authorized_at?->format('d M Y'),
                'doctor' => $doctorId ? User::whereKey($doctorId)->value('name') : null,
                'rx' => $refraction ? self::rxFromRefraction($refraction) : null,
                'blocked' => $refraction ? $this->lookupBlocker($partner, $refraction) : null,
            ];
        })->values()->all();

        $this->recordInClinic($partner, 'optical.patient_lookup', $partner->clinic?->name.' ('.$by->name.') looked up "'.$query.'": '
            .(count($results) ? count($results).' found' : 'nobody found'), ['query' => $query, 'found' => count($results)]);

        return $results;
    }

    /**
     * Optical side: start a job from a looked-up prescription. It becomes the clinic's order sent to us (they see it
     * under Sent to Optical and it goes on their account), then the order form opens on it as for any linked job.
     */
    public function requestFromLookup(int $partnerId, int $refractionId, User $by): OpticalOrderRequest
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $partner = $this->lookupPartner($partnerId);
        $refraction = Refractions::withoutGlobalScopes()->where('clinic_id', $partner->linked_clinic_id)
            ->where('dispensing_required', true)->whereNotNull('dispensing_authorized_at')->findOrFail($refractionId);
        $patient = \App\Models\Patient::withoutGlobalScopes()->findOrFail(
            \App\Models\Consultations::withoutGlobalScopes()->whereKey($refraction->consultation_id)->value('patient_id'));
        if ($blocked = $this->lookupBlocker($partner, $refraction)) throw ValidationException::withMessages(['lookupQuery' => $blocked]);
        $owed = OpticalLinkTermsService::owed($partner);
        if (OpticalLinkTermsService::overLimit($partner, $owed)) {
            throw ValidationException::withMessages(['lookupQuery' => $partner->name.' owes '.currency().' '.number_format($owed, 2).', at its credit limit with you. They pay before new jobs.']);
        }

        $request = OpticalOrderRequest::create([
            'sender_clinic_id' => $partner->linked_clinic_id,
            'sender_branch_id' => $refraction->branch_id,
            'sent_by' => $by->id,
            'optical_clinic_id' => $partner->clinic_id,
            'optical_partner_clinic_id' => $partner->id,
            'refraction_id' => $refraction->id,
            'sender_reference' => $patient->pxnumber,
            'patient_name' => $patient->name,
            'patient_phone' => $patient->contact,
            'rx' => self::rxFromRefraction($refraction),
            'lens_type' => $refraction->lensType,
            'frame_choice' => 'patient_chooses',
            'urgency' => 'normal',
            'notes' => 'Started at '.($partner->clinic?->name ?? 'the optical centre').' by '.$by->name.' from a patient lookup.',
            'status' => 'sent',
            'source' => 'lookup',
            'status_updated_at' => now(),
        ]);
        $this->recordInClinic($partner, 'optical.lookup_order', $partner->clinic?->name.' ('.$by->name.') started a spectacle order for '.$patient->name
            .' from your prescription of '.$refraction->dispensing_authorized_at?->format('d M Y'), ['request_id' => $request->id], $patient->id);
        AuditTrail::record('optical.lookup_order', 'Started a job for '.$patient->name." from {$partner->name}'s records", null, [], ['request_id' => $request->id], null, true);

        return $request;
    }

    private function lookupPartner(int $partnerId): OpticalPartnerClinic
    {
        $partner = $this->lookupPartners()->firstWhere('id', $partnerId);
        if (! $partner) throw ValidationException::withMessages(['lookupQuery' => 'This clinic has not let you look up its patients. Its owner turns it on under Optical Centres.']);

        return $partner;
    }

    /** Why this prescription can't be ordered from here: already ordered or sent somewhere, in the clinic's records. */
    private function lookupBlocker(OpticalPartnerClinic $partner, Refractions $refraction): ?string
    {
        $clinicId = (int) $partner->linked_clinic_id;
        $frontDesk = LensOrder::withoutGlobalScopes()->where('clinic_id', $clinicId)->where('refraction_id', $refraction->id)
            ->where('status', '!=', 'Cancelled')->exists();
        $suite = LensOrder::withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNotIn('status', ['Cancelled', 'Quotation'])
            ->whereIn('optical_prescription_id', \App\Models\OpticalPrescription::withoutGlobalScopes()->where('refraction_id', $refraction->id)->select('id'))->exists();
        if ($frontDesk || $suite) return 'The clinic has already ordered glasses for this prescription.';
        $sent = OpticalOrderRequest::forSender($clinicId)->where('refraction_id', $refraction->id)->where('kind', 'order')
            ->whereIn('status', OpticalOrderRequest::ACTIVE)->whereNull('order_cancelled_at')->exists();

        return $sent ? 'This prescription has already been sent to an optical centre.' : null;
    }

    /** A line in the linked clinic's own audit trail (lookups are always recorded there, whatever its plan). */
    private function recordInClinic(OpticalPartnerClinic $partner, string $event, string $description, array $values, ?int $patientId = null): void
    {
        if (\App\Support\SupportSession::active()) return;
        $row = new AuditTrail;
        $row->forceFill([
            'clinic_id' => $partner->linked_clinic_id,
            'branch_id' => \App\Models\Branch::withoutGlobalScopes()->where('clinic_id', $partner->linked_clinic_id)->orderByDesc('is_default')->orderBy('id')->value('id'),
            'user_id' => auth()->id(), 'patient_id' => $patientId, 'event' => $event, 'description' => $description,
            'new_values' => $values, 'ip_address' => request()?->ip(), 'user_agent' => substr(strip_tags((string) request()?->userAgent()), 0, 500),
        ]);
        // Saved without the tenant hooks, which would stamp the optical centre's clinic on it.
        AuditTrail::withoutEvents(fn () => $row->save());
    }

    /** The optical centre keeps the job (work too far along, say) and tells the clinic why. */
    public function refuseCancel(OpticalOrderRequest $request, string $reason, User $by): void
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $request = $this->incomingRequest($request->id);
        abort_unless($request->cancelPending(), 422, 'There is no cancel request waiting on this job.');
        $reason = $this->clean($reason);
        if (! $reason) throw ValidationException::withMessages(['keepReason' => 'Say why you are keeping the job.']);
        $request->forceFill(['cancel_refused_reason' => $reason, 'cancel_answered_at' => now(), 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
        AuditTrail::record('optical.cancel_refused', 'Kept job '.$request->order_number.' for '.$request->senderLabel().': '.$reason, null, [], ['request_id' => $request->id]);
    }

    /* ---------------------------------------------------------------- remakes (clinic side) */

    public function requestRemake(OpticalOrderRequest $original, string $reason, ?string $note, User $by): OpticalOrderRequest
    {
        app(ClinicAccessService::class)->assertWritable();
        $clinicId = $this->currentClinicId();
        $original = $this->senderRequest($original->id);
        abort_unless($original->canAskRemake(), 422, 'A remake can be asked for once the glasses are ready or collected.');
        if (! array_key_exists($reason, LensOrder::REMAKE_REASONS)) {
            throw ValidationException::withMessages(['remakeReason' => 'Choose why the glasses need remaking.']);
        }
        $partner = OpticalPartnerClinic::withoutGlobalScopes()->findOrFail($original->optical_partner_clinic_id);
        if ((int) $partner->linked_clinic_id !== $clinicId || ! $partner->hasLink()) {
            throw ValidationException::withMessages(['remakeReason' => 'Your clinic is no longer linked to this optical centre. Contact them directly.']);
        }
        $already = OpticalOrderRequest::forSender($clinicId)->where('remake_of_request_id', $original->id)
            ->whereIn('status', ['sent', 'query', 'quoted', 'approved'])->exists();
        if ($already) throw ValidationException::withMessages(['remakeReason' => 'A remake of this job is already waiting at the optical centre.']);

        $remake = OpticalOrderRequest::create([
            'kind' => 'remake', 'remake_of_request_id' => $original->id, 'remake_reason' => $reason,
            'sender_clinic_id' => $clinicId, 'sender_branch_id' => app(TenantContext::class)->branchId(), 'sent_by' => $by->id,
            'optical_clinic_id' => $original->optical_clinic_id, 'optical_partner_clinic_id' => $original->optical_partner_clinic_id,
            'refraction_id' => $original->refraction_id, 'sender_reference' => $original->sender_reference,
            'patient_name' => $original->patient_name, 'patient_phone' => $original->patient_phone, 'rx' => $original->rx,
            'lens_type' => $original->lens_type, 'lens_notes' => $original->lens_notes,
            'frame_choice' => 'note', 'frame_notes' => 'Same frame as job '.$original->order_number,
            'urgency' => 'normal', 'notes' => $this->clean($note), 'status' => 'sent', 'status_updated_at' => now(),
        ]);
        AuditTrail::record('optical.remake_requested', 'Asked '.$partner->clinic?->name.' to remake job '.$original->order_number.' ('.LensOrder::REMAKE_REASONS[$reason].')',
            null, [], ['request_id' => $remake->id, 'remake_of_request_id' => $original->id]);

        return $remake;
    }

    /* ---------------------------------------------------------------- receiving (optical side) */

    public function incomingRequest(int $id): OpticalOrderRequest
    {
        return OpticalOrderRequest::forOptical($this->currentClinicId())->findOrFail($id);
    }

    public function raiseQuery(OpticalOrderRequest $request, string $message, User $by): void
    {
        $request = $this->incomingRequest($request->id);
        abort_unless($request->status === 'sent', 422);
        $message = $this->clean($message);
        if (! $message) throw ValidationException::withMessages(['message' => 'Type your question for the clinic.']);
        $request->forceFill(['status' => 'query', 'query_message' => $message, 'query_answer' => null, 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
    }

    public function reject(OpticalOrderRequest $request, string $reason, User $by): void
    {
        $request = $this->incomingRequest($request->id);
        abort_unless($request->isOpen(), 422);
        $reason = $this->clean($reason);
        if (! $reason) throw ValidationException::withMessages(['reason' => 'Say why, so the clinic can tell the patient.']);
        $request->forceFill(['status' => 'rejected', 'reject_reason' => $reason, 'status_updated_at' => now(), 'handled_by' => $by->id])->save();
    }

    /** The order form saved an order (or quotation) from this request. */
    public function markAccepted(int $requestId, LensOrder $order): void
    {
        DB::transaction(function () use ($requestId, $order) {
            $request = OpticalOrderRequest::forOptical($this->currentClinicId())->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'sent') {
                throw ValidationException::withMessages(['request' => 'This request was changed by the clinic while you worked on it ('.$request->progressLabel().'). Check it in Incoming Orders.']);
            }
            $request->forceFill([
                'status' => 'accepted', 'lens_order_id' => $order->id, 'order_number' => $order->order_id,
                'order_status' => (string) $order->status, 'status_updated_at' => now(), 'handled_by' => auth()->id(),
                'order_total' => round((float) $order->total, 2), 'order_paid' => round((float) $order->paid_amount, 2),
            ])->save();
        });
    }

    /**
     * The order form saved a quotation from this request: the clinic approves the price before
     * the job starts. A quotation sent earlier for the same request (expired) is cancelled.
     *
     * When the quote is within the clinic's auto-approve amount (and its credit limit), it is approved
     * at once and the job starts; returns true then.
     *
     * @param  array<int, array{label: string, amount: float}>  $lines
     */
    public function markQuoted(int $requestId, LensOrder $quotation, array $lines): bool
    {
        return DB::transaction(function () use ($requestId, $quotation, $lines) {
            $request = OpticalOrderRequest::forOptical($this->currentClinicId())->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'sent') {
                throw ValidationException::withMessages(['request' => 'This request was changed by the clinic while you worked on it ('.$request->progressLabel().'). Check it in Incoming Orders.']);
            }
            $previous = $request->lens_order_id;
            $request->forceFill([
                'status' => 'quoted', 'lens_order_id' => $quotation->id, 'order_number' => $quotation->order_id, 'order_status' => 'Quotation',
                'quote_total' => round((float) $quotation->total, 2), 'quote_valid_until' => $quotation->quote_valid_until,
                'quote_lines' => array_values(array_map(fn ($line) => ['label' => (string) $line['label'], 'amount' => round((float) $line['amount'], 2)], $lines)),
                'quoted_at' => now(), 'approved_at' => null, 'approved_by' => null, 'auto_approved' => false, 'decline_reason' => null,
                'order_total' => round((float) $quotation->total, 2), 'order_paid' => 0, 'order_cancelled_at' => null,
                'status_updated_at' => now(), 'handled_by' => auth()->id(),
            ])->save();
            if ($previous && (int) $previous !== (int) $quotation->id && LensOrder::whereKey($previous)->where('status', 'Quotation')->exists()) {
                app(\App\Services\OpticalOrderWorkflowService::class)->cancel((int) $previous);
            }
            AuditTrail::record('optical.quote_sent', 'Sent a quote of '.currency().' '.number_format((float) $quotation->total, 2).' to '.$request->senderClinic?->name.' for '.$request->patient_name,
                $quotation, [], ['request_id' => $request->id]);

            $link = OpticalPartnerClinic::withoutGlobalScopes()->with('clinic')->find($request->optical_partner_clinic_id);
            if (! $link || ! OpticalLinkTermsService::autoApproves($link, (float) $quotation->total)) return false;
            $request->forceFill(['status' => 'approved', 'approved_at' => now(), 'auto_approved' => true, 'status_updated_at' => now()])->save();
            AuditTrail::record('optical.quote_auto_approved', 'Quote of '.currency().' '.number_format((float) $quotation->total, 2).' for '.$request->patient_name
                .' approved automatically (within '.$request->senderClinic?->name.' limit of '.currency().' '.number_format((float) $link->auto_approve_limit, 2).')', $quotation, [], ['request_id' => $request->id]);
            $this->startJob($request);

            return true;
        });
    }

    /** The clinic approved the quote: turn the quotation into a job. Prices stay as approved. */
    public function startJob(OpticalOrderRequest $request): LensOrder
    {
        $request = $this->incomingRequest($request->id);
        abort_unless($request->status === 'approved' && $request->lens_order_id, 422, 'Only an approved quote can be started.');

        return app(\App\Services\OpticalOrderWorkflowService::class)->activateQuotation((int) $request->lens_order_id, 0, 'cash', false);
    }

    /** A quotation linked to a request starts only once the clinic approves it. */
    public static function assertQuoteApproved(LensOrder $order): void
    {
        $request = OpticalOrderRequest::where('lens_order_id', $order->id)->where('optical_clinic_id', $order->clinic_id)->latest('id')->first();
        if ($request && $request->status !== 'approved') {
            throw ValidationException::withMessages(['order' => 'This quote was sent to '.$request->senderLabel().' online. Start it from Incoming Orders once they approve it.']);
        }
    }

    /** A job linked to a request is paid through the clinic's account, so no deposit is taken. */
    public static function isLinkedOrder(LensOrder $order): bool
    {
        return $order->id && OpticalOrderRequest::where('lens_order_id', $order->id)->where('optical_clinic_id', $order->clinic_id)->exists();
    }

    /** Called when a linked job changes (status, price, payments): the clinic sees it on its side. */
    public static function syncFromOrder(LensOrder $order): void
    {
        $requests = OpticalOrderRequest::where('lens_order_id', $order->id)->where('optical_clinic_id', $order->clinic_id)->get();
        foreach ($requests as $request) {
            $fill = [
                'order_status' => (string) $order->status, 'order_number' => $order->order_id,
                'order_total' => round((float) $order->total, 2), 'order_paid' => round((float) $order->paid_amount, 2),
                'order_cancelled_at' => $order->status === 'Cancelled' ? ($order->cancelled_at ?? now()) : null,
                'status_updated_at' => now(),
            ];
            if ($request->status === 'approved' && ! in_array($order->status, ['Quotation', 'Cancelled'], true)) $fill['status'] = 'accepted';
            if (in_array($request->status, ['quoted', 'approved'], true) && $order->status === 'Cancelled') {
                $fill['status'] = 'rejected';
                $fill['reject_reason'] = $request->reject_reason ?: 'The optical centre withdrew its quote.';
            }
            $request->forceFill($fill)->save();
        }
    }

    /** Whether this optical centre takes orders online: a linked partner, or requests already received. */
    public static function receivesOnline(): bool
    {
        $clinicId = app(TenantContext::class)->clinicId();
        if (! $clinicId) return false;

        return OpticalPartnerClinic::withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNotNull('linked_clinic_id')->exists()
            || OpticalOrderRequest::forOptical($clinicId)->exists();
    }

    /** Requests waiting at this optical centre: new, or approved quotes to start (menu badge). */
    public static function newIncomingCount(): int
    {
        $clinicId = app(TenantContext::class)->clinicId();

        return $clinicId ? OpticalOrderRequest::forOptical($clinicId)->whereIn('status', ['sent', 'approved'])->count() : 0;
    }

    /** Requests of this clinic waiting on it: a question to answer or a quote to approve (clinic menu badge). */
    public static function senderQueryCount(): int
    {
        $clinicId = app(TenantContext::class)->clinicId();

        return $clinicId ? OpticalOrderRequest::forSender($clinicId)->whereIn('status', ['query', 'quoted'])->count() : 0;
    }

    /* ---------------------------------------------------------------- helpers */

    public static function rxFromRefraction(Refractions $rx): array
    {
        return [
            'od' => ['sph' => $rx->subjective_od_sphere, 'cyl' => $rx->subjective_od_cylinder, 'axis' => $rx->subjective_od_axis, 'add' => $rx->subjective_od_add],
            'os' => ['sph' => $rx->subjective_os_sphere, 'cyl' => $rx->subjective_os_cylinder, 'axis' => $rx->subjective_os_axis, 'add' => $rx->subjective_os_add],
            'pd' => $rx->pd,
        ];
    }

    /** Hosted optical centres need the optical module on their subscription; a single install has it or not by licence. */
    public function opticalCanReceive(Clinic $clinic): bool
    {
        if (! app(ClinicAccessService::class)->hosted($clinic)) return true;
        $access = app(SubscriptionService::class)->access($clinic, Feature::OPTICAL);

        return $access['allowed'] && ! $access['read_only'];
    }

    public function canManageLinks(?User $user): bool
    {
        return \App\Support\Access\Access::allows($user, 'optical_centres.manage');
    }

    private function senderRequest(int $id): OpticalOrderRequest
    {
        return OpticalOrderRequest::forSender($this->currentClinicId())->findOrFail($id);
    }

    private function currentClinicId(): int
    {
        $id = app(TenantContext::class)->clinicId();
        abort_unless($id, 403);

        return (int) $id;
    }

    private function newCode(): string
    {
        do {
            // No 0/O or 1/I, so a code read over the phone is typed right.
            $code = collect(range(1, 8))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        } while (OpticalLinkInvite::where('code', $code)->exists());

        return $code;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : Str::limit($text, 2000, '');
    }
}
