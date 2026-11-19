<?php

namespace App\Support\Access;

use App\Models\User;
use App\Support\RequestMemo;
use App\Support\Tenancy\BranchRoleManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Per-person switches, per clinic (phase 4): a permission switched on that the person's role
 * lacks, or switched off that it has. Read once per request per person.
 */
final class PersonalAccess
{
    private const TABLE = 'clinic_user_permissions';

    public static function clinicId(): int
    {
        return app(TenantContext::class)->clinicId() ?? 0;
    }

    /** @return array<string, bool> permission => switched on (true) / off (false), this clinic */
    public static function overrides(User $user): array
    {
        $clinicId = self::clinicId();

        return app(RequestMemo::class)->remember("personal-access:{$clinicId}:{$user->id}", function () use ($clinicId, $user) {
            try {
                return DB::table(self::TABLE)->where('clinic_id', $clinicId)->where('user_id', $user->id)
                    ->pluck('granted', 'permission')->map(fn ($granted) => (bool) $granted)->all();
            } catch (\Illuminate\Database\QueryException) {
                return []; // table not migrated yet: roles alone decide
            }
        });
    }

    public static function override(User $user, string $permission): ?bool
    {
        return self::overrides($user)[$permission] ?? null;
    }

    /** What the person's role(s) give, ignoring their own switches. */
    public static function fromRole(User $user, string $permission): bool
    {
        if (RoleMap::grants($user->getRoleNames(), $permission)) {
            return true;
        }
        $legacy = Permissions::legacyNames($permission);

        return $legacy !== [] && $user->getAllPermissions()->pluck('name')->intersect($legacy)->isNotEmpty();
    }

    /**
     * Set (true/false) or clear (null = as the role gives) one switch.
     * @return array{0: ?bool, 1: ?bool} [before, after]
     */
    public static function set(User $user, string $permission, ?bool $granted, ?int $by): array
    {
        $clinicId = self::clinicId();
        $before = self::override($user, $permission);
        $key = ['clinic_id' => $clinicId, 'user_id' => $user->id, 'permission' => $permission];
        if ($granted === null) {
            DB::table(self::TABLE)->where($key)->delete();
        } else {
            DB::table(self::TABLE)->updateOrInsert($key, ['granted' => $granted, 'set_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        }
        self::forget($user);

        return [$before, $granted];
    }

    public static function forget(User $user): void
    {
        app(RequestMemo::class)->forget('personal-access:'.self::clinicId().':'.$user->id);
    }

    /** Clears every switch for this person in this clinic (e.g. when they leave it). */
    public static function clear(User $user): void
    {
        DB::table(self::TABLE)->where('clinic_id', self::clinicId())->where('user_id', $user->id)->delete();
        self::forget($user);
    }

    /**
     * The person as they are in the current branch: their branch roles, not whatever roles their
     * last sign-in elsewhere left on the account. Used to judge and preview someone else's access.
     */
    public static function asInThisBranch(User $user): User
    {
        $branchId = app(TenantContext::class)->branchId();
        $subject = clone $user;
        if ($branchId) {
            $subject->setRelation('roles', app(BranchRoleManager::class)->roles($user, $branchId));
        } else {
            $subject->load('roles');
        }

        return $subject;
    }
}
