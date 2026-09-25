<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionManagerComponent extends Component
{
    public string $activeTab = 'roles';

    // ── Role form ──────────────────────────────────────────────────────────────
    public ?int  $editingRoleId        = null;
    public string $roleName            = '';
    public string $dashboardRoute      = '';
    public array  $selectedPermissions = [];
    public array  $originalRolePermissions = [];

    public ?int $managingRoleId = null;
    public string $managingRoleName = '';
    public ?int $userToAssign = null;

    // ── Permission form ────────────────────────────────────────────────────────
    public ?int  $editingPermissionId = null;
    public string $permissionName     = '';

    // These roles are referenced by routes, layouts, and dashboards.
    protected array $protectedRoles = ['Super Admin', 'Manager', 'Doctor', 'Secretary', 'Cashier', 'Staff'];

    protected array $permissionPresets = [
        'Clinical' => [
            'view consultations',
            'perform refraction',
            'view medical records',
            'manage referrals',
        ],
        'Billing' => [
            'manage billing',
            'process refunds',
            'view sales records',
            'view outstanding balances',
        ],
        'Approvals' => [
            'approve discounts',
            'approve refunds',
            'approve clearance revoke',
        ],
        'Inventory' => [
            'manage inventory',
            'receive stock',
            'manage suppliers',
            'manage purchase orders',
        ],
        'Reports' => [
            'view reports',
            'view income statement',
            'export reports',
        ],
        'System' => [
            'manage users',
            'manage settings',
            'manage backups',
            'view audit trail',
        ],
    ];

    protected array $roleTemplates = [
        'Doctor' => ['view consultations', 'perform refraction', 'view medical records', 'manage referrals'],
        'Cashier' => ['manage billing', 'view sales records', 'view outstanding balances'],
        'Secretary' => ['view consultations', 'view sales records'],
        'Manager' => ['manage billing', 'view reports', 'view income statement', 'approve discounts', 'approve refunds', 'approve clearance revoke'],
        'Inventory Staff' => ['manage inventory', 'receive stock', 'manage suppliers', 'manage purchase orders'],
        'Accountant' => ['manage billing', 'view reports', 'view income statement', 'export reports', 'view sales records'],
    ];

    public function mount(): void
    {
        $this->authorizeRoleManagement();
    }

    // ── Roles ──────────────────────────────────────────────────────────────────

    public function openCreateRole(): void
    {
        $this->editingRoleId        = null;
        $this->roleName             = '';
        $this->dashboardRoute       = '';
        $this->selectedPermissions  = [];
        $this->originalRolePermissions = [];
        $this->resetErrorBag();
        $this->dispatch('show-roleModal', ...['isEdit' => false]);
    }

    public function openEditRole(int $id): void
    {
        $role = Role::with('permissions')->findOrFail($id);
        $this->editingRoleId        = $id;
        $this->roleName             = $role->name;
        $this->dashboardRoute       = $role->dashboard_route ?? '';
        $this->selectedPermissions  = $role->permissions->pluck('id')->map(fn ($i) => (string) $i)->toArray();
        $this->originalRolePermissions = $role->permissions->pluck('name')->sort()->values()->all();
        $this->resetErrorBag();
        $this->dispatch('show-roleModal', ...['isEdit' => true]);
    }

    public function applyRoleTemplate(string $template): void
    {
        $this->authorizeRoleManagement();

        if (!array_key_exists($template, $this->roleTemplates)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Role template not found.']);
            return;
        }

        $ids = [];
        foreach ($this->roleTemplates[$template] as $permissionName) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $ids[] = (string) $permission->id;
        }

        $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $ids)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        cache()->forget('role_permission_manager.permission_usage');

        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$template} template applied. Review and save the role."]);
    }

    public function saveRole(): void
    {
        $this->authorizeRoleManagement();

        $uniqueRule = 'unique:roles,name' . ($this->editingRoleId ? ",{$this->editingRoleId}" : '');
        $this->validate(['roleName' => "required|string|max:64|{$uniqueRule}"]);

        $perms = Permission::whereIn('id', $this->selectedPermissions)->get();

        if ($this->editingRoleId) {
            $role    = Role::with('permissions')->findOrFail($this->editingRoleId);
            $oldName = $role->name;
            $oldPerms = $role->permissions->pluck('name')->toArray();

            if (in_array($role->name, $this->protectedRoles) && $role->name !== $this->roleName) {
                $this->addError('roleName', 'The name of a protected role cannot be changed.');
                return;
            }

            $role->update([
                'name'            => $this->roleName,
                'dashboard_route' => $this->dashboardRoute ?: null,
            ]);
            $role->syncPermissions($perms);

            AuditTrail::record(
                'role.updated',
                "Updated role \"{$oldName}\"" . ($oldName !== $role->name ? " → \"{$role->name}\"" : '') . '.',
                $role,
                ['name' => $oldName, 'permissions' => $oldPerms],
                ['name' => $role->name, 'permissions' => $perms->pluck('name')->toArray(),
                 'dashboard_route' => $role->dashboard_route]
            );
            $msg = "Role \"{$role->name}\" updated.";
        } else {
            $role = Role::create([
                'name'            => $this->roleName,
                'guard_name'      => 'web',
                'dashboard_route' => $this->dashboardRoute ?: null,
            ]);
            $role->syncPermissions($perms);

            AuditTrail::record(
                'role.created',
                "Created role \"{$role->name}\".",
                $role,
                [],
                ['name' => $role->name, 'permissions' => $perms->pluck('name')->toArray(),
                 'dashboard_route' => $role->dashboard_route]
            );
            $msg = "Role \"{$role->name}\" created.";
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->dispatch('hide-roleModal');
        $this->dispatch('notify', ...['type' => 'success', 'message' => $msg]);
    }

    public function deleteRole(int $id): void
    {
        $this->authorizeRoleManagement();
        $role = Role::withCount('users')->findOrFail($id);

        if (in_array($role->name, $this->protectedRoles)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => "\"{$role->name}\" is a protected role and cannot be deleted."]);
            return;
        }

        if ($role->users_count > 0) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => "Cannot delete \"{$role->name}\" — {$role->users_count} user(s) still assigned. Re-assign them first."]);
            return;
        }

        $name = $role->name;
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditTrail::record('role.deleted', "Deleted role \"{$name}\".");
        $this->dispatch('notify', ...['type' => 'info', 'message' => "Role \"{$name}\" deleted."]);
    }

    public function openRoleUsers(int $id): void
    {
        $role = Role::findOrFail($id);
        $this->managingRoleId = $role->id;
        $this->managingRoleName = $role->name;
        $this->userToAssign = null;
        $this->resetErrorBag();
        $this->dispatch('show-roleUsersModal');
    }

    public function assignUserToManagedRole(): void
    {
        $this->authorizeRoleManagement();

        abort_if(!$this->managingRoleId, 404);
        $this->validate(['userToAssign' => 'required|exists:users,id']);

        $role = Role::findOrFail($this->managingRoleId);
        $user = User::findOrFail($this->userToAssign);
        $oldRoles = $user->getRoleNames()->all();

        $user->assignRole($role);

        AuditTrail::record('role.user_assigned', "Assigned {$user->name} to role \"{$role->name}\".", $user, [
            'roles' => $oldRoles,
        ], [
            'roles' => $user->fresh()->getRoleNames()->all(),
        ]);

        $this->userToAssign = null;
        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$user->name} assigned to {$role->name}."]);
    }

    public function removeUserFromManagedRole(int $userId): void
    {
        $this->authorizeRoleManagement();

        abort_if(!$this->managingRoleId, 404);

        $role = Role::findOrFail($this->managingRoleId);
        $user = User::findOrFail($userId);

        if ($role->name === 'Super Admin' && $user->id === auth()->id()) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'You cannot remove your own Super Admin role.']);
            return;
        }

        $oldRoles = $user->getRoleNames()->all();
        $user->removeRole($role);

        AuditTrail::record('role.user_removed', "Removed {$user->name} from role \"{$role->name}\".", $user, [
            'roles' => $oldRoles,
        ], [
            'roles' => $user->fresh()->getRoleNames()->all(),
        ]);

        $this->dispatch('notify', ...['type' => 'info', 'message' => "{$user->name} removed from {$role->name}."]);
    }

    // ── Permissions ────────────────────────────────────────────────────────────

    public function openCreatePermission(): void
    {
        $this->editingPermissionId = null;
        $this->permissionName      = '';
        $this->resetErrorBag();
        $this->dispatch('show-permissionModal', ...['isEdit' => false]);
    }

    public function openEditPermission(int $id): void
    {
        $perm = Permission::findOrFail($id);
        $this->editingPermissionId = $id;
        $this->permissionName      = $perm->name;
        $this->resetErrorBag();
        $this->dispatch('show-permissionModal', ...['isEdit' => true]);
    }

    public function savePermission(): void
    {
        $this->authorizeRoleManagement();

        $uniqueRule = 'unique:permissions,name' . ($this->editingPermissionId ? ",{$this->editingPermissionId}" : '');
        $this->validate(['permissionName' => "required|string|max:128|{$uniqueRule}"]);

        if ($this->editingPermissionId) {
            $perm = Permission::findOrFail($this->editingPermissionId);
            $old  = $perm->name;
            $perm->update(['name' => $this->permissionName]);
            AuditTrail::record(
                'permission.updated',
                "Renamed permission \"{$old}\" to \"{$perm->name}\".",
                $perm,
                ['name' => $old],
                ['name' => $perm->name]
            );
            $msg = "Permission \"{$old}\" renamed to \"{$perm->name}\".";
        } else {
            $perm = Permission::create(['name' => $this->permissionName, 'guard_name' => 'web']);
            AuditTrail::record(
                'permission.created',
                "Created permission \"{$perm->name}\".",
                $perm, [], ['name' => $perm->name]
            );
            $msg = "Permission \"{$perm->name}\" created.";
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        cache()->forget('role_permission_manager.permission_usage');
        $this->dispatch('hide-permissionModal');
        $this->dispatch('notify', ...['type' => 'success', 'message' => $msg]);
    }

    public function deletePermission(int $id): void
    {
        $this->authorizeRoleManagement();
        $perm = Permission::findOrFail($id);
        $name = $perm->name;
        $perm->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        cache()->forget('role_permission_manager.permission_usage');
        AuditTrail::record('permission.deleted', "Deleted permission \"{$name}\".");
        $this->dispatch('notify', ...['type' => 'info', 'message' => "Permission \"{$name}\" deleted and removed from all roles."]);
    }

    public function createPermissionPreset(string $group): void
    {
        $this->authorizeRoleManagement();

        if (!array_key_exists($group, $this->permissionPresets)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Permission preset not found.']);
            return;
        }

        $created = 0;
        foreach ($this->permissionPresets[$group] as $permissionName) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);

            if ($permission->wasRecentlyCreated) {
                $created++;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        cache()->forget('role_permission_manager.permission_usage');
        AuditTrail::record('permission.preset_created', "Applied {$group} permission preset.", null, [], [
            'group' => $group,
            'created' => $created,
        ]);

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => $created > 0
                ? "{$group} preset added {$created} new permission(s)."
                : "{$group} preset already exists.",
        ]);
    }

    private function authorizeRoleManagement(): void
    {
        abort_if(!auth()->user()?->hasRole('Super Admin'), 403);
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    public function render()
    {
        $permissions = Permission::with('roles')->orderBy('name')->get();
        $permissionUsage = $this->permissionUsageMap($permissions->pluck('name')->all());
        $rolePermissionPreview = $this->rolePermissionPreview($permissions);
        $managingRole = $this->managingRoleId
            ? Role::with('users.roles')->find($this->managingRoleId)
            : null;

        return view('livewire.admin.role-permission-manager-component', [
            'roles' => Role::with(['permissions'])
                ->withCount(['users', 'permissions'])
                ->orderBy('name')
                ->get(),
            'permissions' => $permissions,
            'protectedRoles' => $this->protectedRoles,
            'permissionPresets' => $this->permissionPresets,
            'roleTemplates' => $this->roleTemplates,
            'permissionUsage' => $permissionUsage,
            'rolePermissionPreview' => $rolePermissionPreview,
            'managingRole' => $managingRole,
            'assignableUsers' => User::with('roles')->orderBy('name')->get(),
        ])->layout('layouts.admin.admin-layout');
    }

    private function rolePermissionPreview($permissions): array
    {
        $selectedNames = $permissions
            ->whereIn('id', collect($this->selectedPermissions)->map(fn ($id) => (int) $id)->all())
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        return [
            'added' => array_values(array_diff($selectedNames, $this->originalRolePermissions)),
            'removed' => array_values(array_diff($this->originalRolePermissions, $selectedNames)),
        ];
    }

    private function permissionUsageMap(array $permissionNames): array
    {
        return cache()->remember('role_permission_manager.permission_usage', now()->addMinutes(10), function () use ($permissionNames) {
            $map = array_fill_keys($permissionNames, []);
            $folders = [
                app_path(),
                base_path('routes'),
                resource_path('views'),
            ];

            foreach ($folders as $folder) {
                if (!is_dir($folder)) {
                    continue;
                }

                foreach (File::allFiles($folder) as $file) {
                    $path = str_replace('\\', '/', $file->getPathname());
                    if (str_contains($path, 'RolePermissionManagerComponent.php') ||
                        str_contains($path, 'role-permission-manager-component.blade.php')) {
                        continue;
                    }

                    $contents = @file_get_contents($path);
                    if ($contents === false) {
                        continue;
                    }

                    foreach ($permissionNames as $permissionName) {
                        if ($this->permissionIsEnforcedIn($contents, $permissionName)) {
                            $map[$permissionName][] = str_replace('\\', '/', $file->getRelativePathname());
                        }
                    }
                }
            }

            return $map;
        });
    }

    private function permissionIsEnforcedIn(string $contents, string $permissionName): bool
    {
        $quoted = preg_quote($permissionName, '/');

        return preg_match("/can\\(['\"]{$quoted}['\"]\\)/", $contents) === 1
            || preg_match("/@can\\(['\"]{$quoted}['\"]\\)/", $contents) === 1
            || preg_match("/permission:[^'\"\\]\\)]*{$quoted}/", $contents) === 1
            || preg_match("/role_or_permission:[^'\"\\]\\)]*{$quoted}/", $contents) === 1;
    }
}
