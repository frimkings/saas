<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PasswordResetRequest extends Model
{
    protected $fillable = ['email', 'status', 'approved_by', 'admin_note', 'actioned_at'];

    protected $casts = ['actioned_at' => 'datetime'];

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
