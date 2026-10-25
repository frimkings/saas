<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class PasswordResetRequest extends Model
{
    protected $fillable = ['email', 'status', 'approved_by', 'admin_note', 'actioned_at', 'code_hash', 'code_expires_at', 'code_attempts'];

    protected $casts = ['actioned_at' => 'datetime', 'code_expires_at' => 'datetime', 'code_attempts' => 'integer'];

    protected $hidden = ['code_hash'];

    /** How long the manager's code works, and how many wrong tries close the request. */
    public const CODE_MINUTES = 30;
    public const CODE_TRIES = 5;

    protected static function booted(): void
    {
        // Requests aren't stored per clinic (the guest asking only gives an email), so on hosted
        // installs a clinic's admins see, count and act on requests only from their own active
        // staff. Never platform accounts: approving one would let a clinic take it over. With no
        // clinic resolved (the public forgot-password pages, platform pages) nothing is filtered.
        static::addGlobalScope('clinic_staff', function (Builder $query): void {
            $context = app(TenantContext::class);
            if (! config('tenancy.enabled') || ! $context->resolved()) {
                return;
            }

            $query->whereIn($query->qualifyColumn('email'), User::query()
                ->select('users.email')
                ->where('users.is_platform_admin', false)
                ->whereHas('clinics', fn (Builder $clinics) => $clinics
                    ->whereKey($context->clinicId())
                    ->where('clinic_user.status', 'active')));
        });

        // The sidebar's pending-approvals badge is cached; refresh it on any change.
        static::saved(fn () => \App\Support\ApprovalCounts::forget());
        static::deleted(fn () => \App\Support\ApprovalCounts::forget());
    }

    public function actionedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isApproved(): bool  { return $this->status === 'approved'; }
    public function isRejected(): bool  { return $this->status === 'rejected'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }

    /**
     * A new one-time code for the approved request; the manager hands it to the staff member in
     * person. Only its hash is kept, so the plain code is returned once, here.
     */
    public function issueCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->update(['code_hash' => Hash::make($code), 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'code_attempts' => 0]);

        return $code;
    }

    /** Why the code can't be used ('none', 'expired'), or null when it can be tried. */
    public function codeProblem(): ?string
    {
        if (! $this->code_hash) return 'none';
        if (! $this->code_expires_at || $this->code_expires_at->isPast()) return 'expired';

        return null;
    }

    /** Checks a code typed in; wrong ones count, and too many close the request. */
    public function acceptsCode(string $code): bool
    {
        if ($this->codeProblem() !== null) return false;
        if (Hash::check(preg_replace('/\D/', '', $code), $this->code_hash)) return true;

        $this->increment('code_attempts');
        if ($this->code_attempts >= self::CODE_TRIES) {
            $this->update(['status' => 'rejected', 'code_hash' => null, 'admin_note' => trim(($this->admin_note ? $this->admin_note.' · ' : '').'Closed after '.self::CODE_TRIES.' wrong codes.')]);
        }

        return false;
    }

    /** Latest open (pending or approved) request for an email. */
    public static function latestFor(string $email): ?self
    {
        return static::where('email', $email)
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->first();
    }

    public static function pendingCount(): int
    {
        return static::where('status', 'pending')->count();
    }
}
