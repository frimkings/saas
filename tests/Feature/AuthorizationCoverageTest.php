<?php

namespace Tests\Feature;

use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\Admin\RolePermissionManagerComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AuthorizationCoverageTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('protectedPageMatrix')]
    public function test_standard_roles_are_checked_against_each_protected_page_group(
        string $routeName,
        string $roleName,
        bool $allowed
    ): void {
        $user = $this->userWithRole($roleName);

        $this->assertSame(
            $allowed,
            $this->routeAuthorizationAllows($routeName, $user),
            "{$roleName} access contract failed for route {$routeName}."
        );
    }

    public static function protectedPageMatrix(): array
    {
        return [
            'cashier opens POS' => ['cashier.seller-desk', 'Cashier', true],
            'secretary opens POS' => ['cashier.seller-desk', 'Secretary', true],
            'manager opens POS' => ['cashier.seller-desk', 'Manager', true],
            'super admin opens POS' => ['cashier.seller-desk', 'Super Admin', true],
            'doctor cannot open POS' => ['cashier.seller-desk', 'Doctor', false],

            'secretary opens patient registry' => ['secretary.patients', 'Secretary', true],
            'manager opens patient registry' => ['secretary.patients', 'Manager', true],
            'super admin opens patient registry' => ['secretary.patients', 'Super Admin', true],
            'cashier cannot open patient registry' => ['secretary.patients', 'Cashier', false],
            'doctor cannot open patient registry' => ['secretary.patients', 'Doctor', false],

            'doctor opens clinical records' => ['doctor.all-records', 'Doctor', true],
            'super admin opens clinical records' => ['doctor.all-records', 'Super Admin', true],
            'manager cannot open clinical records' => ['doctor.all-records', 'Manager', false],
            'secretary cannot open clinical records' => ['doctor.all-records', 'Secretary', false],
            'cashier cannot open clinical records' => ['doctor.all-records', 'Cashier', false],

            'manager opens administration' => ['admin.dashboard', 'Manager', true],
            'super admin opens administration' => ['admin.dashboard', 'Super Admin', true],
            'doctor cannot open administration' => ['admin.dashboard', 'Doctor', false],
            'secretary cannot open administration' => ['admin.dashboard', 'Secretary', false],
            'cashier cannot open administration' => ['admin.dashboard', 'Cashier', false],

            'super admin manages role definitions' => ['admin.roles-permissions', 'Super Admin', true],
            'manager cannot manage role definitions' => ['admin.roles-permissions', 'Manager', false],
            'doctor cannot manage role definitions' => ['admin.roles-permissions', 'Doctor', false],
            'secretary cannot manage role definitions' => ['admin.roles-permissions', 'Secretary', false],
            'cashier cannot manage role definitions' => ['admin.roles-permissions', 'Cashier', false],
        ];
    }

    public function test_every_role_area_route_declares_authentication_and_authorization_middleware(): void
    {
        $roleAreaPrefixes = ['secretary/', 'cashier/', 'admin/', 'doctor/'];
        $checkedRoutes = 0;

        foreach (Route::getRoutes() as $route) {
            if (!collect($roleAreaPrefixes)->contains(
                fn (string $prefix) => str_starts_with($route->uri(), $prefix)
            )) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $checkedRoutes++;

            $this->assertContains('auth', $middleware, "{$route->uri()} must require authentication.");
            $this->assertTrue(
                collect($middleware)->contains(
                    fn (string $entry) =>
                        str_starts_with($entry, 'role:') ||
                        str_starts_with($entry, 'role_or_permission:')
                ),
                "{$route->uri()} must declare a role or permission access rule."
            );
        }

        $this->assertGreaterThan(0, $checkedRoutes, 'No protected role-area routes were discovered.');
    }

    public function test_custom_roles_receive_only_pages_granted_by_direct_permissions(): void
    {
        $manageUsers = Permission::firstOrCreate(['name' => 'manage users', 'guard_name' => 'web']);
        $manageBilling = Permission::firstOrCreate(['name' => 'manage billing', 'guard_name' => 'web']);

        $userAdministrator = $this->userWithRole('HR Administrator', [$manageUsers]);
        $billingSupervisor = $this->userWithRole('Billing Supervisor', [$manageBilling]);
        $unprivileged = $this->userWithRole('Front Desk Custom');

        $this->assertTrue($this->routeAuthorizationAllows('admin.users', $userAdministrator));
        $this->assertFalse($this->routeAuthorizationAllows('admin.dashboard', $userAdministrator));

        $this->assertTrue($this->routeAuthorizationAllows('admin.approvals', $billingSupervisor));
        $this->assertFalse($this->routeAuthorizationAllows('admin.users', $billingSupervisor));

        $this->assertFalse($this->routeAuthorizationAllows('admin.users', $unprivileged));
        $this->assertFalse($this->routeAuthorizationAllows('admin.approvals', $unprivileged));
    }

    public function test_role_management_action_rechecks_super_admin_authority(): void
    {
        $superAdmin = $this->userWithRole('Super Admin');
        $component = Livewire::actingAs($superAdmin)
            ->test(RolePermissionManagerComponent::class)
            ->set('roleName', 'Unauthorized Role');

        $superAdmin->removeRole('Super Admin');
        $superAdmin->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('saveRole')->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'Unauthorized Role']);
    }

    public function test_refund_action_rechecks_manager_authority(): void
    {
        $manager = $this->userWithRole('Manager');
        $component = Livewire::actingAs($manager)
            ->test(RefundApprovalsComponent::class);

        $manager->removeRole('Manager');
        $manager->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('confirmApprove', 999999)->assertForbidden();
    }

    private function userWithRole(string $roleName, array $permissions = []): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        if ($permissions) {
            $role->syncPermissions($permissions);
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function routeAuthorizationAllows(string $routeName, User $user): bool
    {
        $route = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, "Named route {$routeName} does not exist.");
        $this->assertContains('auth', $route->gatherMiddleware(), "{$routeName} must require authentication.");

        $authorizationMiddleware = collect($route->gatherMiddleware())
            ->first(fn (string $middleware) =>
                str_starts_with($middleware, 'role:') ||
                str_starts_with($middleware, 'role_or_permission:')
            );

        $this->assertNotNull(
            $authorizationMiddleware,
            "{$routeName} must declare role or permission middleware."
        );

        [$type, $arguments] = explode(':', $authorizationMiddleware, 2);
        $request = Request::create(route($routeName, $this->placeholderParameters($routeName)), 'GET');
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            $middleware = $type === 'role'
                ? app(RoleMiddleware::class)
                : app(RoleOrPermissionMiddleware::class);

            $response = $middleware->handle(
                $request,
                fn () => response('authorized'),
                $arguments
            );

            return $response->getStatusCode() === Response::HTTP_OK;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            if ($exception->getStatusCode() === Response::HTTP_FORBIDDEN) {
                return false;
            }

            throw $exception;
        }
    }

    private function placeholderParameters(string $routeName): array
    {
        return match ($routeName) {
            default => [],
        };
    }
}
