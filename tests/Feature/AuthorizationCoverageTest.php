<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureScreenAccess;
use App\Livewire\Admin\RefundApprovalsComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

            // Read-only since phase 5: owners and managers see what each role can do.
            'super admin sees what each role can do' => ['admin.roles-permissions', 'Super Admin', true],
            'manager sees what each role can do' => ['admin.roles-permissions', 'Manager', true],
            'doctor cannot see the role guide' => ['admin.roles-permissions', 'Doctor', false],
            'secretary cannot see the role guide' => ['admin.roles-permissions', 'Secretary', false],
            'cashier cannot see the role guide' => ['admin.roles-permissions', 'Cashier', false],
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
            $this->assertContains('access', $middleware, "{$route->uri()} must declare the access rule.");
        }

        $this->assertGreaterThan(0, $checkedRoutes, 'No protected role-area routes were discovered.');
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

        $this->assertContains(
            'access',
            $route->gatherMiddleware(),
            "{$routeName} must declare the access middleware."
        );

        $request = Request::create(route($routeName, $this->placeholderParameters($routeName)), 'GET');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            $response = app(EnsureScreenAccess::class)->handle(
                $request,
                fn () => response('authorized')
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
