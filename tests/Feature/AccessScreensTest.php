<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureScreenAccess;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\Access\Permissions;
use App\Support\Access\RoleMap;
use App\Support\Access\Screens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 1 of docs/roles-and-permissions.md: route access moved from role names to permissions
 * without anyone gaining or losing a screen. tests/Fixtures/route-access-before.json is the old
 * role rules of every guarded route, captured before the switch.
 */
class AccessScreensTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['Super Admin', 'Manager', 'Secretary', 'Cashier', 'Doctor', 'Staff', 'Optician', 'Optical Assistant', 'Lab Technician'];

    private function before(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/route-access-before.json')), true);
    }

    /**
     * The old rules: every role rule had to name the role, or (role_or_permission) the person
     * had to hold one of the listed database permissions — as the migrations give them.
     */
    private function oldAllows(array $conditions, User $user, string $role): bool
    {
        $held = $user->getAllPermissions()->pluck('name')->all();
        foreach ($conditions as $condition) {
            $byPermission = $condition['kind'] === 'RoleOrPermissionMiddleware'
                && array_intersect($condition['permissions'], $held) !== [];
            if (! in_array($role, $condition['roles'], true) && ! $byPermission) {
                return false;
            }
        }

        return true;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    /**
     * Screens a role was deliberately taken off since the snapshot (decided 2026-11-18):
     * Managers don't get sales reports, the income / combined statement or clinic settings
     * (the pages themselves already refused them); Opticians are bench-only, so no till; accepting
     * an optical-centre link is for whoever manages optical centres (it lands on that page).
     */
    private const DELIBERATE = [
        'Manager' => [
            'admin.reports', 'reports.export.pdf', 'admin.income-statement', 'admin.income-statement.preview',
            'admin.income-statement.export.csv', 'admin.income-statement.export.pdf',
            'admin.combined-statement', 'admin.combined-statement.export', 'admin.settings',
        ],
        'Optician' => [
            'cart', 'cashier.dashboard', 'cashier.seller-desk', 'cashier.clearance-receipt', 'cashier.receipt.show',
            'cashier.receipt.pdf', 'cashier.visit-receipt.sale', 'cashier.visit-receipt.show', 'cashier.sales-records',
            'cashier.outstanding-balances', 'refunds.logs', 'refunds.receipt', 'refunds.receipt.pdf',
        ],
        'Secretary' => ['optical-links.accept'],
    ];

    /** Removed: pointed at a POS method that no longer existed (500 for everyone). */
    private const REMOVED = ['GET livewire/ajax-patients'];

    public function test_every_role_opens_exactly_the_screens_it_opened_before(): void
    {
        $differences = [];
        foreach (self::ROLES as $role) {
            $user = $this->userWithRole($role);
            foreach ($this->before() as $name => $route) {
                if (in_array($name, self::REMOVED, true)) {
                    continue;
                }
                $old = $this->oldAllows($route['conditions'], $user, $role)
                    && ! in_array($name, self::DELIBERATE[$role] ?? [], true);
                $new = Access::allowsRoute($user, $name);
                if ($old !== $new) {
                    $differences[] = "{$role} on {$name}: before ".($old ? 'yes' : 'no').', now '.($new ? 'yes' : 'no');
                }
            }
        }

        $this->assertSame([], $differences);
    }

    public function test_legacy_database_permissions_still_open_their_screens(): void
    {
        foreach (['record insurer payments' => 'admin.insurance.payments', 'manage users' => 'admin.users'] as $legacy => $route) {
            $role = Role::firstOrCreate(['name' => 'Legacy '.$legacy, 'guard_name' => 'web']);
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $legacy, 'guard_name' => 'web']));
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertTrue(Access::allowsRoute($user, $route), "{$legacy} should still open {$route}");
            $this->assertFalse(Access::allowsRoute($user, 'admin.settings'));
        }
    }

    public function test_every_guarded_route_is_in_the_screen_map_and_vice_versa(): void
    {
        $guarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array(EnsureScreenAccess::class, Route::gatherRouteMiddleware($route), true))
            ->map(fn ($route) => $route->getName());

        $this->assertNotContains(null, $guarded->all(), 'Every route behind `access` needs a name.');
        $this->assertSame([], $guarded->diff(array_keys(Screens::MAP))->values()->all(), 'Routes behind `access` missing from Screens::MAP');
        $this->assertSame([], collect(array_keys(Screens::MAP))->diff($guarded)->values()->all(), 'Screens::MAP entries with no guarded route');
    }

    public function test_no_route_still_uses_role_name_rules(): void
    {
        $this->assertDoesNotMatchRegularExpression("/'(role|role_or_permission):/", file_get_contents(base_path('routes/web.php')));
    }

    public function test_screen_map_and_role_map_only_use_known_permissions(): void
    {
        foreach (Screens::MAP as $route => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertTrue(Permissions::exists($permission), "{$route} uses unknown permission {$permission}");
            }
        }
        foreach (self::ROLES as $role) {
            foreach (RoleMap::permissionsFor($role) as $permission) {
                $this->assertTrue(Permissions::exists($permission), "{$role} has unknown permission {$permission}");
            }
        }
    }

    public function test_a_screen_refuses_someone_without_access(): void
    {
        $this->actingAs($this->userWithRole('Doctor'))
            ->get(route('admin.settings'))
            ->assertForbidden();
    }
}
