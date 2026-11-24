<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

class NotificationService
{
    /**
     * Send a notification to one specific user.
     */
    public static function send(
        int $userId,
        string $type,
        string $title,
        string $body,
        string $icon = 'fas fa-bell',
        string $iconColor = 'text-primary',
        ?string $actionUrl = null,
        ?array $data = null
    ): ?AppNotification {
        $context = app(TenantContext::class);

        if (config('tenancy.enabled')) {
            if (! $context->resolved()) {
                throw new LogicException('A clinic and branch context is required to send notifications.');
            }

            $allowed = DB::table('clinic_user')
                ->join('branch_user', 'branch_user.user_id', '=', 'clinic_user.user_id')
                ->where('clinic_user.clinic_id', $context->clinicId())
                ->where('branch_user.branch_id', $context->branchId())
                ->where('clinic_user.user_id', $userId)
                ->where('clinic_user.status', 'active')
                ->where('branch_user.status', 'active')
                ->exists();

            if (! $allowed) {
                // A platform admin who acted inside the clinic as support (e.g. asked for a refund)
                // is not a member of it: skip their bell notification instead of failing the
                // owner's approve/reject/process action after it has already been saved.
                if (DB::table('users')->where('id', $userId)->value('is_platform_admin')) {
                    return null;
                }
                throw new LogicException('Notification recipient is outside the active clinic branch.');
            }
        }

        return AppNotification::create([
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'icon'       => $icon,
            'icon_color' => $iconColor,
            'action_url' => $actionUrl,
            'data'       => $data,
        ]);
    }

    /**
     * Send to active users holding a requested role in the active branch.
     */
    public static function sendToRoles(
        array $roles,
        string $type,
        string $title,
        string $body,
        string $icon = 'fas fa-bell',
        string $iconColor = 'text-primary',
        ?string $actionUrl = null,
        ?array $data = null,
        ?int $excludeUserId = null
    ): void {
        $context = app(TenantContext::class);

        if (config('tenancy.enabled') && ! $context->resolved()) {
            throw new LogicException('A clinic and branch context is required to send role notifications.');
        }

        $userIds = DB::table('branch_user_role as bur')
            ->join('roles as r', 'r.id', '=', 'bur.role_id')
            ->join('branch_user as bu', function ($join): void {
                $join->on('bu.branch_id', '=', 'bur.branch_id')
                    ->on('bu.user_id', '=', 'bur.user_id');
            })
            ->join('clinic_user as cu', 'cu.user_id', '=', 'bur.user_id')
            ->where('bur.branch_id', $context->branchId())
            ->where('cu.clinic_id', $context->clinicId())
            ->where('bu.status', 'active')
            ->where('cu.status', 'active')
            ->whereIn('r.name', $roles)
            ->distinct()
            ->pluck('bur.user_id');

        User::whereIn('id', $userIds)
            ->when($excludeUserId, fn ($query) => $query->where('id', '!=', $excludeUserId))
            ->each(function (User $user) use ($type, $title, $body, $icon, $iconColor, $actionUrl, $data) {
                static::send($user->id, $type, $title, $body, $icon, $iconColor, $actionUrl, $data);
            });
    }
}
