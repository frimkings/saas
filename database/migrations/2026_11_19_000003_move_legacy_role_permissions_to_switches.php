<?php

use App\Support\Access\RoleMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles phase 5: the old database permissions some clinics added to shared roles (before the
     * hotfix) stop counting. Nobody loses access: where a person relied on one and their role
     * doesn't give it, they get a per-person switch in each clinic where they hold that role.
     * Then the old permissions are taken off roles and people. Optical permissions are untouched.
     */
    private const LEGACY = [
        'view medical records' => ['clinical.records'],
        'record insurer payments' => ['insurance.payments'],
        'manage billing' => ['approve.discounts', 'approve.refunds', 'approve.revokes', 'reports.sales', 'reports.finance'],
        'approve discounts' => ['approve.discounts'],
        'approve clearance revoke' => ['approve.revokes'],
        'manage users' => ['staff.manage'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('clinic_user_permissions') || ! Schema::hasTable('role_has_permissions')) return;

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::LEGACY))->pluck('id', 'name');
        if ($permissionIds->isEmpty()) return;
        $now = now();

        // Held through a role.
        $held = DB::table('role_has_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->whereIn('rp.permission_id', $permissionIds->values())
            ->select('r.id as role_id', 'r.name as role', 'rp.permission_id')->get();
        foreach ($held as $row) {
            $legacy = $permissionIds->search($row->permission_id);
            $missing = array_values(array_filter(self::LEGACY[$legacy], fn ($key) => ! RoleMap::grants([$row->role], $key)));
            if (! $missing) continue;
            $people = DB::table('branch_user_role as bur')->join('branches as b', 'b.id', '=', 'bur.branch_id')
                ->join('clinic_user as cu', fn ($join) => $join->on('cu.user_id', '=', 'bur.user_id')->on('cu.clinic_id', '=', 'b.clinic_id'))
                ->where('bur.role_id', $row->role_id)->whereIn('cu.status', ['active', 'inactive'])
                ->distinct()->get(['b.clinic_id', 'bur.user_id']);
            $this->switchOn($people, $missing, $now);
        }

        // Given to a person directly.
        $direct = DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds->values())
            ->where('model_type', \App\Models\User::class)->get(['model_id', 'permission_id']);
        foreach ($direct as $row) {
            $legacy = $permissionIds->search($row->permission_id);
            $people = DB::table('clinic_user')->where('user_id', $row->model_id)->whereIn('status', ['active', 'inactive'])
                ->get(['clinic_id', 'user_id']);
            $this->switchOn($people, self::LEGACY[$legacy], $now);
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds->values())->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds->values())->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Never overrides a switch a clinic already set. Owners, and people another role covers, need none. */
    private function switchOn($people, array $keys, $now): void
    {
        foreach ($people as $person) {
            $roles = DB::table('branch_user_role as bur')->join('branches as b', 'b.id', '=', 'bur.branch_id')
                ->join('roles as r', 'r.id', '=', 'bur.role_id')
                ->where('bur.user_id', $person->user_id)->where('b.clinic_id', $person->clinic_id)->pluck('r.name')->unique()->all();
            if (in_array(RoleMap::OWNER, $roles, true)) continue;
            foreach ($keys as $key) {
                if (RoleMap::grants($roles, $key)) continue; // another of their roles gives it
                DB::table('clinic_user_permissions')->insertOrIgnore([
                    'clinic_id' => $person->clinic_id, 'user_id' => $person->user_id, 'permission' => $key,
                    'granted' => true, 'set_by' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // One-way: the switches made here stay (they grant exactly what people had).
    }
};
