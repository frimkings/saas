<?php

namespace App\Services;

use App\Mail\OwnerNoticeMail;
use App\Models\ClearanceRevokeLog;
use App\Models\Clinic;
use App\Models\DiscountApprovalRequest;
use App\Models\OpticalStockCount;
use App\Models\PasswordResetRequest;
use App\Models\RefundLog;
use App\Models\Sales;
use App\Models\SubscriptionChangeRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Approval emails to the clinic owner: something is waiting for a decision (a discount, a
 * refund, a staff password reset), or the platform has decided on the clinic's plan change.
 * The button opens the screen where the decision is made.
 */
class OwnerAlerts
{
    public function __construct(private readonly OwnerMailer $mailer) {}

    public function discountRequested(DiscountApprovalRequest $request): void
    {
        $clinic = $this->currentClinic();
        if (! $clinic) return;
        $request->loadMissing(['cashier', 'patient']);
        $money = fn ($amount) => $this->money($clinic, $amount);
        $discount = $request->discount_type === 'percentage'
            ? rtrim(rtrim(number_format((float) $request->discount_value, 2), '0'), '.') . '% (' . $money($request->discount_amount) . ')'
            : $money($request->discount_amount);

        $this->notice($clinic, 'discount_request', 'discount:' . $request->id,
            'Discount waiting for approval',
            ($request->cashier?->name ?? 'A staff member') . ' asked to give a discount and needs your approval before the sale can go through.',
            array_filter([
                'Requested by' => $request->cashier?->name,
                'Customer' => $request->patient?->name ?? 'Walk-in',
                'Discount' => $discount,
                'Sale total' => $money($request->gross_amount),
                'After discount' => $money($request->final_amount),
                'Branch' => app(TenantContext::class)->branch()?->name,
            ]),
            'Review discount', route('admin.approvals', ['type' => 'discount']));
    }

    public function refundRequested(RefundLog $refund, Sales $sale): void
    {
        $clinic = $this->currentClinic();
        if (! $clinic) return;
        $type = $refund->request_type === 'void' ? 'Void' : 'Refund';

        $this->notice($clinic, 'refund_request', 'refund:' . $refund->id,
            "{$type} waiting for approval",
            (auth()->user()?->name ?? 'A staff member') . ' asked to ' . strtolower($type) . ' a sale. Nothing is paid back until you approve it.',
            array_filter([
                'Requested by' => auth()->user()?->name,
                'Transaction' => '#' . $sale->transaction_id,
                'Sale total' => $this->money($clinic, $sale->total_amount),
                'Reason' => RefundLog::REASON_CODES[$refund->reason_code] ?? null,
                'Note' => $refund->reason,
                'Branch' => app(TenantContext::class)->branch()?->name,
            ]),
            'Review ' . strtolower($type), route('admin.approvals', ['type' => 'refund']));
    }

    /** A staff member asked for a password reset: every clinic they work at is told. */
    public function passwordResetRequested(PasswordResetRequest $request): void
    {
        $user = User::where('email', $request->email)->first();
        if (! $user) return;
        $clinics = $user->clinics()->where('clinics.status', 'active')->wherePivot('status', 'active')->get();
        foreach ($clinics as $clinic) {
            $this->notice($clinic, 'password_reset_request', 'password_reset:' . $request->id,
                'Password reset waiting for approval',
                "{$user->name} forgot their password and asked for a reset. They can't sign in until you approve it.",
                ['Staff member' => $user->name, 'Email' => $user->email, 'Requested' => $request->created_at?->timezone($this->timezone($clinic))->format('d M Y, g:i A')],
                'Review request', route('admin.approvals', ['type' => 'password_reset']),
                "If {$user->name} didn't ask for this, reject it and let them know.");
        }
    }

    /** The platform approved or rejected the clinic's request to change plan. */
    public function planRequestReviewed(SubscriptionChangeRequest $request): void
    {
        $request->loadMissing(['clinic', 'requestedPlan']);
        if (! $request->clinic || ! in_array($request->status, ['approved', 'rejected'], true)) return;
        $approved = $request->status === 'approved';

        $this->notice($request->clinic, 'plan_request_' . $request->status, 'plan_request:' . $request->id . ':' . $request->status,
            $approved ? 'Your plan change was approved' : 'Your plan change was not approved',
            $approved
                ? 'Your request to change plan was approved. The new plan starts at the end of your current billing period.'
                : 'Your request to change plan was not approved. Your current plan continues unchanged.',
            array_filter([
                'Requested plan' => $request->requestedPlan?->name,
                'Billing' => $request->billing_interval ? ucfirst($request->billing_interval) : null,
                'Note' => $request->review_notes,
            ]),
            'View subscription', route('admin.subscription'));
    }

    /** A cashier asked to revoke a patient's clearance. */
    public function revokeRequested(ClearanceRevokeLog $log, string $patientName): void
    {
        $clinic = $this->currentClinic();
        if (! $clinic) return;

        $this->notice($clinic, 'revoke_request', 'revoke:' . $log->id,
            'Clearance revoke waiting for approval',
            (auth()->user()?->name ?? 'A staff member') . " asked to revoke {$patientName}'s clearance. It stays in place until you approve.",
            array_filter([
                'Requested by' => auth()->user()?->name,
                'Patient' => $patientName,
                'Reason' => $log->reason,
                'Branch' => app(TenantContext::class)->branch()?->name,
            ]),
            'Review request', route('admin.approvals', ['type' => 'revoke']));
    }

    /** SMS credits fell below the warning level: patients stop getting messages when they run out. */
    public function smsCreditsLow(int $clinicId, int $balance): void
    {
        $clinic = Clinic::find($clinicId);
        if (! $clinic) return;

        $this->notice($clinic, 'sms_credits_low', 'sms_low:' . now()->toDateString(),
            'SMS credits are running low',
            "Only {$balance} SMS credits are left. When they run out, patients stop getting appointment, receipt and collection messages.",
            ['Credits left' => number_format($balance)],
            'Buy SMS credits', route('admin.settings', ['tab' => 'sms']));
    }

    /** Someone joined the clinic's staff. */
    public function staffAdded(User $staff, array $roles): void
    {
        $this->staffNotice($staff, 'added', 'New staff member added',
            (auth()->user()?->name ?? 'Someone') . " added {$staff->name} to the clinic's staff.", $roles);
    }

    /**
     * Staff added in bulk from a CSV file: one email for the whole import.
     *
     * @param  array<int, array{name: string, email: string, role: string}>  $people
     */
    public function staffImported(array $people): void
    {
        $clinic = $this->currentClinic();
        if (! $clinic || ! $people) return;
        $count = count($people);
        $admins = array_values(array_filter($people, fn ($person) => in_array($person['role'], self::ADMIN_ROLES, true)));
        $details = [];
        foreach (array_slice($people, 0, 10) as $person) {
            $details[$person['name']] = $person['role'] . ' · ' . $person['email'];
        }
        if ($count > 10) $details['And'] = ($count - 10) . ' more';

        $this->notice($clinic, 'staff_imported', 'staff:imported:' . now()->format('YmdHisv'),
            $count === 1 ? '1 staff member imported' : "{$count} staff members imported",
            (auth()->user()?->name ?? 'Someone') . " added {$count} " . ($count === 1 ? 'person' : 'people') . " to the clinic's staff from a spreadsheet."
                . ($admins ? ' ' . count($admins) . ' of them ' . (count($admins) === 1 ? 'has' : 'have') . ' admin access: ' . implode(', ', array_column($admins, 'name')) . '.' : ''),
            $details,
            'View staff', route('admin.users'),
            "If you didn't expect this, check with " . (auth()->user()?->name ?? 'your team') . '.');
    }

    /** Someone was given Manager or Super Admin rights. */
    public function staffPromoted(User $staff, array $roles): void
    {
        $this->staffNotice($staff, 'promoted', 'Staff member given admin access',
            (auth()->user()?->name ?? 'Someone') . " gave {$staff->name} " . implode(' and ', array_intersect(self::ADMIN_ROLES, $roles)) . ' access. They can now manage staff and see the business figures.', $roles);
    }

    /** Someone was removed from the clinic's staff. */
    public function staffRemoved(User $staff): void
    {
        $this->staffNotice($staff, 'removed', 'Staff member removed',
            (auth()->user()?->name ?? 'Someone') . " removed {$staff->name} from the clinic's staff. They can no longer sign in here.", []);
    }

    /** A stock count was approved and stock was corrected: the owner sees what went missing. */
    public function stockCountApproved(OpticalStockCount $count): void
    {
        $clinic = $this->currentClinic();
        if (! $clinic) return;
        $count->loadMissing('lines.product');
        $lines = $count->lines->filter(fn ($line) => $line->variance());
        if ($lines->isEmpty()) return;

        $short = $lines->filter(fn ($line) => $line->variance() < 0);
        $over = $lines->filter(fn ($line) => $line->variance() > 0);
        $value = fn ($set) => $set->sum(fn ($line) => abs($line->variance()) * (float) $line->unit_cost);
        $worst = $short->sortBy(fn ($line) => $line->variance() * (float) $line->unit_cost)->take(3)
            ->map(fn ($line) => ($line->product?->name ?? 'Item') . ' (' . $line->variance() . ')')->implode(', ');

        $this->notice($clinic, 'stock_count', 'count:' . $count->id,
            $short->isNotEmpty() ? 'Stock count found missing stock' : 'Stock count found extra stock',
            "Count {$count->count_number} was approved by " . (auth()->user()?->name ?? 'a manager') . ' and stock was corrected to match what was counted.',
            array_filter([
                'Items short' => $short->isNotEmpty() ? $short->sum(fn ($line) => abs($line->variance())) . ' units · ' . $this->money($clinic, $value($short)) . ' at cost' : null,
                'Items over' => $over->isNotEmpty() ? $over->sum(fn ($line) => $line->variance()) . ' units · ' . $this->money($clinic, $value($over)) . ' at cost' : null,
                'Biggest shortfalls' => $worst ?: null,
                'Branch' => app(TenantContext::class)->branch()?->name,
            ]),
            'View count', route('optical.stock-counts'),
            $short->isNotEmpty() ? 'Missing stock can mean theft, breakages or sales not recorded. Worth asking the team.' : null);
    }

    private const ADMIN_ROLES = ['Manager', 'Super Admin'];

    /** Whether a role list gives admin access that the old list didn't. */
    public static function grantsAdmin(array $before, array $after): bool
    {
        return array_diff(array_intersect(self::ADMIN_ROLES, $after), $before) !== [];
    }

    private function staffNotice(User $staff, string $event, string $heading, string $intro, array $roles): void
    {
        $clinic = $this->currentClinic();
        if (! $clinic) return;

        $this->notice($clinic, 'staff_' . $event, "staff:{$event}:{$staff->id}:" . now()->format('YmdHis'), $heading, $intro,
            array_filter([
                'Name' => $staff->name,
                'Email' => $staff->email,
                'Roles' => $roles ? implode(', ', $roles) : null,
                'Done by' => auth()->user()?->name,
            ]),
            'View staff', route('admin.users'),
            "If you didn't expect this, check with " . (auth()->user()?->name ?? 'your team') . '.');
    }

    private function notice(Clinic $clinic, string $kind, string $key, string $heading, string $intro, array $details, string $button, string $url, ?string $footnote = null): void
    {
        $this->mailer->send($clinic, $kind, $key, $heading . ' - ' . $clinic->name,
            fn () => new OwnerNoticeMail($clinic->name, $heading, $intro, $details, $button, $url, $footnote));
    }

    private function currentClinic(): ?Clinic
    {
        return app(TenantContext::class)->clinic()
            ?? (config('tenancy.enabled') ? null : \App\Models\Setting::first()?->clinic);
    }

    private function money(Clinic $clinic, $amount): string
    {
        return trim(($clinic->default_currency ?: currency()) . ' ' . number_format((float) $amount, 2));
    }

    private function timezone(Clinic $clinic): string
    {
        return $clinic->default_timezone ?: config('app.timezone');
    }
}
