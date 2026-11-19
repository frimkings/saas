<?php

namespace App\Services\OpticalLinks;

use App\Models\AuditTrail;
use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerClinic;
use App\Models\User;
use App\Services\ClinicAccessService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Agreed terms on a clinic–optical link (decided 2026-11-18).
 *
 *  - Trade %: the optical centre takes this much off its own prices for lenses, frames and services
 *    on every job the clinic sends. It shows as one "Agreed trade discount" line on the quote.
 *  - Credit limit: what the clinic may owe the optical centre. At or over it, the clinic can't send
 *    new jobs (remakes and jobs already sent carry on) until it pays down.
 *  - Auto-approve: the clinic names an amount; quotes up to it are approved at once and the job starts.
 *  - The platform can pause or end a link with a reason; only the platform lifts its own pause.
 *
 * The terms live on the link (the optical centre's partner row): the optical centre sets trade % and
 * credit, the clinic sets its auto-approve limit, each only through here.
 */
class OpticalLinkTermsService
{
    /* ---------------------------------------------------------------- optical side */

    public function setTerms(OpticalPartnerClinic $partner, array $terms, User $by): void
    {
        abort_unless(\App\Support\Access\Access::allows($by, 'optical.manage'), 403);
        app(ClinicAccessService::class)->assertWritable();
        $partner = OpticalPartnerClinic::findOrFail($partner->id); // tenant-scoped: the optical centre's own partner
        abort_unless((int) $partner->clinic_id === $this->clinicId(), 404);

        $fill = [];
        $errors = [];
        foreach (OpticalPartnerClinic::TRADE_KINDS as $kind => $column) {
            $value = trim((string) ($terms[$kind] ?? ''));
            if ($value === '') { $fill[$column] = null; continue; }
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 90) { $errors['terms.'.$kind] = 'Enter a % from 0 to 90, or leave it blank.'; continue; }
            $fill[$column] = (float) $value > 0 ? round((float) $value, 2) : null;
        }
        $credit = trim((string) ($terms['credit'] ?? ''));
        if ($credit === '') $fill['credit_limit'] = null;
        elseif (! is_numeric($credit) || (float) $credit < 0 || (float) $credit > 10000000) $errors['terms.credit'] = 'Enter an amount, or leave it blank for no limit.';
        else $fill['credit_limit'] = round((float) $credit, 2);
        if ($errors) throw ValidationException::withMessages($errors);

        $old = $partner->only(array_keys($fill));
        $partner->forceFill($fill + ['terms_updated_at' => now()])->save();
        AuditTrail::record('optical.link_terms', 'Agreed terms for '.$partner->name.': trade '.$partner->tradeSummary().', credit limit '
            .($partner->credit_limit !== null ? currency().' '.number_format($partner->credit_limit, 2) : 'none'), $partner, $old, $fill);
    }

    /* ---------------------------------------------------------------- clinic side */

    /** The clinic's own setting: approve quotes from this optical centre up to this amount without asking. */
    public function setAutoApprove(int $partnerId, ?string $limit, User $by): void
    {
        abort_unless(\App\Support\Access\Access::allows($by, 'optical.manage'), 403);
        app(ClinicAccessService::class)->assertWritable();
        $partner = OpticalPartnerClinic::withoutGlobalScopes()->where('linked_clinic_id', $this->clinicId())->findOrFail($partnerId);
        $limit = trim((string) $limit);
        if ($limit !== '' && (! is_numeric($limit) || (float) $limit < 0 || (float) $limit > 1000000)) {
            throw ValidationException::withMessages(['autoApprove.'.$partnerId => 'Enter an amount, or leave it blank to approve every quote yourself.']);
        }
        $value = $limit === '' || (float) $limit <= 0 ? null : round((float) $limit, 2);
        $old = $partner->auto_approve_limit;
        $partner->forceFill(['auto_approve_limit' => $value])->save();
        AuditTrail::record('optical.link_auto_approve', 'Quotes from '.$partner->clinic?->name.' approved automatically '
            .($value !== null ? 'up to '.currency().' '.number_format($value, 2) : ': off'), null, ['auto_approve_limit' => $old], ['auto_approve_limit' => $value, 'partner_id' => $partner->id]);
    }

    /* ---------------------------------------------------------------- credit (either side reads) */

    /** What the clinic owes on this link: jobs started and not cancelled, less what the optical centre has confirmed paid. */
    public static function owed(OpticalPartnerClinic $link): float
    {
        return round(OpticalOrderRequest::where('optical_partner_clinic_id', $link->id)->where('status', 'accepted')
            ->whereNull('order_cancelled_at')->whereNotNull('order_total')->get(['order_total', 'order_paid'])
            ->sum(fn ($job) => max(0, (float) $job->order_total - (float) $job->order_paid)), 2);
    }

    /** At or over the credit limit: the clinic can't send new jobs. */
    public static function overLimit(OpticalPartnerClinic $link, ?float $owed = null): bool
    {
        return $link->credit_limit !== null && ($owed ?? self::owed($link)) >= (float) $link->credit_limit;
    }

    public static function creditMessage(OpticalPartnerClinic $link, float $owed): string
    {
        return 'Your clinic owes '.currency().' '.number_format($owed, 2).', which has reached the credit limit of '
            .currency().' '.number_format((float) $link->credit_limit, 2).' with '.($link->clinic?->name ?? 'this optical centre')
            .'. Pay them under Owed to Optical, and you can send new jobs once they confirm it.';
    }

    /**
     * A quote just sent: approve it at once when it is within the clinic's auto-approve amount and
     * keeps the clinic within its credit limit. Returns true when approved.
     */
    public static function autoApproves(OpticalPartnerClinic $link, float $quoteTotal): bool
    {
        if ($link->auto_approve_limit === null || $quoteTotal > (float) $link->auto_approve_limit) return false;
        if ($link->credit_limit !== null && self::owed($link) + $quoteTotal > (float) $link->credit_limit) return false;

        return true;
    }

    /* ---------------------------------------------------------------- platform */

    public function platformSetStatus(int $partnerId, string $status, ?string $reason, User $by): void
    {
        abort_unless(in_array($status, ['active', 'paused', 'ended'], true), 422);
        abort_unless((bool) $by->is_platform_admin, 403);
        $partner = OpticalPartnerClinic::withoutGlobalScopes()->with(['clinic', 'linkedClinic'])->whereNotNull('linked_clinic_id')->findOrFail($partnerId);
        abort_unless($partner->hasLink(), 422, 'This link has already ended.');
        $reason = trim((string) $reason);
        if ($status !== 'active' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why. Both clinics see it.']);
        }
        if ($status === 'active' && $partner->link_status === 'paused' && ! $partner->link_paused_by_platform) {
            throw ValidationException::withMessages(['reason' => 'One of the clinics paused this link; they resume it themselves.']);
        }
        $old = $partner->link_status;
        $partner->forceFill([
            'link_status' => $status,
            'link_paused_by_platform' => $status === 'paused',
            'link_paused_by_clinic_id' => null,
            'link_status_reason' => $status === 'active' ? null : mb_substr($reason, 0, 500),
        ])->save();
        // Platform actions go in the platform's own log, against both clinics.
        foreach (array_unique([(int) $partner->clinic_id, (int) $partner->linked_clinic_id]) as $clinicId) {
            app(\App\Services\PlatformAuditService::class)->record('OPTICAL_LINK_'.strtoupper($status), $clinicId,
                ['link_status' => $old], ['link_status' => $status, 'partner_id' => $partner->id, 'optical_clinic_id' => $partner->clinic_id, 'clinic_id' => $partner->linked_clinic_id], $reason !== '' ? $reason : null);
        }
    }

    private function clinicId(): int
    {
        $id = app(TenantContext::class)->clinicId();
        abort_unless($id, 403);

        return (int) $id;
    }
}
