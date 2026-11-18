<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\Access\Screens;
use App\Support\ClinicNavigation;
use App\Support\NavigationWorkspace;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * The pages themselves agree with the screen map: every role really opens the pages its
 * permissions allow and is refused the rest, and its menu only links to pages it can open.
 */
class AccessPagesTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private const ROLES = ['Super Admin', 'Manager', 'Secretary', 'Cashier', 'Doctor', 'Optician', 'Optical Assistant', 'Lab Technician', 'Staff'];

    private Clinic $clinic;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $this->clinic = $this->activeClinic(['name' => 'Access Clinic', 'slug' => 'access-clinic']);
        $this->branchId = $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true])->id;
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        $this->redirector = $this->app->make('redirect');
    }

    private \Illuminate\Routing\Redirector $redirector;

    /** Pages refused for reasons that are not roles (kept out so they don't hide role problems). */
    private const NOT_ROLE_GATED = [
        'admin.backups' => 'full backups are for the hosting operator on hosted clinics',
    ];

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($roleModel);
        $this->clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        DB::table('branch_user')->insert(['branch_id' => $this->branchId, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
        // Signed in, a person's roles are their roles at the branch they work in.
        DB::table('branch_user_role')->insert(['branch_id' => $this->branchId, 'user_id' => $user->id, 'role_id' => $roleModel->id]);
        app(TenantContext::class)->set($user, $this->clinic, $this->clinic->branches()->first(), [$this->branchId]);
        Setting::getSettings()->forceFill([
            'clinic_address' => '1 Test Street', 'clinic_contact' => '0200000000', 'clinic_email' => 'clinic@example.test',
        ])->save();

        // Freshly loaded, as a real sign-in would be (the created object keeps a stale empty role list).
        return $user->fresh();
    }

    /** @return list<string> GET routes in the screen map that need no parameters */
    private function pages(): array
    {
        return collect(Screens::MAP)->keys()
            ->filter(function (string $name) {
                $route = Route::getRoutes()->getByName($name);
                if (! $route || ! in_array('GET', $route->methods(), true) || $route->parameterNames()
                    || isset(self::NOT_ROLE_GATED[$name])) {
                    return false;
                }
                // Pages the clinic's plan doesn't include are a plan question, not a role one.
                foreach ($route->gatherMiddleware() as $middleware) {
                    if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                        foreach (explode(',', substr($middleware, 8)) as $feature) {
                            if (! \App\Services\LicenseService::has($feature)) {
                                return false;
                            }
                        }
                    }
                }

                return true;
            })->values()->all();
    }

    public function test_each_role_opens_its_pages_and_is_refused_the_rest(): void
    {
        $problems = [];
        foreach (self::ROLES as $role) {
            $user = $this->staff($role);
            foreach ($this->pages() as $name) {
                $expected = Access::allowsRoute($user, $name);
                // Livewire swaps in its own redirector while a component renders; a real request starts clean.
                $this->app->instance('redirect', $this->redirector);
                $status = $this->actingAs($user)->get(route($name))->getStatusCode();
                if ($expected && ($status === 403 || $status >= 500)) {
                    $problems[] = "{$role} should open {$name} but got {$status}";
                }
                // Refused, or sent to their own start page (first visit after sign-in) — never shown the page.
                if (! $expected && ! in_array($status, [302, 403], true)) {
                    $problems[] = "{$role} should be refused {$name} but got {$status}";
                }
            }
            auth()->logout();
        }

        $this->assertSame([], $problems);
    }

    public function test_menus_only_link_to_pages_the_person_can_open(): void
    {
        $problems = [];
        foreach (self::ROLES as $role) {
            $user = $this->staff($role);
            $this->actingAs($user);
            [, $layout] = ClinicNavigation::sharedLayout();
            // The admin menu follows the page it is on: an administration page and a clinical one.
            $pages = $layout['menu'] === 'admin' ? ['admin.dashboard', 'admin.clinical-task-center'] : [null];
            foreach ($pages as $page) {
                $this->onPage($page);
                $menu = $layout['menu'].($page ? " ({$page})" : '');
                foreach ($this->links(ClinicNavigation::menu($layout['menu'])) as $route) {
                    if (Screens::permissionsFor($route) !== null && ! Access::allowsRoute($user, $route)) {
                        $problems[] = "{$role}'s {$menu} menu links to {$route}, which they can't open";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }

    private function onPage(?string $routeName): void
    {
        $request = request();
        $route = $routeName ? Route::getRoutes()->getByName($routeName) : null;
        $request->setRouteResolver(fn () => $route);
    }

    /** @return list<string> */
    private function links(array $items): array
    {
        $routes = [];
        foreach ($items as $item) {
            if (isset($item['route'])) {
                $routes[] = $item['route'];
            }
            if (isset($item['items'])) {
                $routes = [...$routes, ...$this->links($item['items'])];
            }
        }

        return $routes;
    }
}
