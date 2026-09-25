<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClinicAccessService;
use App\Support\NavigationWorkspace;
use App\Support\Tenancy\RoleDashboardResolver;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(ClinicAccessService::class, function ($mock) {
            $mock->shouldReceive('access')->andReturn(['allowed' => true, 'read_only' => false]);
        });
    }

    private function staff(string $role): User
    {
        $user = new User(['name' => 'Navigation test']);
        $user->id = 42;
        $user->setRelation('roles', new Collection([new Role(['name' => $role, 'guard_name' => 'web'])]));
        return $user;
    }

    public function test_roles_receive_only_their_existing_workspaces(): void
    {
        $this->assertSame(['administration', 'clinical', 'optical'], array_keys(NavigationWorkspace::available($this->staff('Super Admin'))));
        $this->assertSame(['clinical'], array_keys(NavigationWorkspace::available($this->staff('Doctor'))));
        $this->assertSame(['optical'], array_keys(NavigationWorkspace::available($this->staff('Optician'))));
        $this->assertSame(['clinical', 'optical'], array_keys(NavigationWorkspace::available($this->staff('Secretary'))));
    }

    public function test_switch_remembers_choice_and_redirects_to_workspace_landing_page(): void
    {
        $user = $this->staff('Super Admin');
        \Illuminate\Support\Facades\Route::matched(function ($event) {
            $event->request->setLaravelSession(session()->driver());
        });
        $this->withoutMiddleware()->actingAs($user)
            ->post(route('navigation.workspace'), ['workspace' => 'optical'])
            ->assertRedirect(route('optical.dashboard'))
            ->assertSessionHas(NavigationWorkspace::preferenceKey($user), 'optical')
            ->assertPlainCookie(NavigationWorkspace::preferenceKey($user), 'optical');
        $this->assertSame('optical.dashboard', app(RoleDashboardResolver::class)->routeName($user));
    }

    public function test_switch_cannot_grant_an_unavailable_workspace(): void
    {
        $this->withoutMiddleware()->actingAs($this->staff('Doctor'))
            ->post(route('navigation.workspace'), ['workspace' => 'administration'])->assertForbidden();
    }

    public function test_stale_preference_is_ignored_after_role_changes(): void
    {
        $user = $this->staff('Doctor');
        session()->put(NavigationWorkspace::preferenceKey($user), 'administration');
        $this->assertSame('doctor.dashboard', app(RoleDashboardResolver::class)->routeName($user));
    }

    public function test_single_area_staff_do_not_get_a_switcher(): void
    {
        $this->actingAs($this->staff('Optician'));
        $this->view('components.navigation-workspace')
            ->assertSee('Optical workspace')->assertDontSee('<select', false);
    }

    public function test_direct_optical_order_link_keeps_orders_active(): void
    {
        $request = request();
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('GET', 'optical/orders/create', fn () => null))->name('optical.orders.create'));
        $this->assertSame('optical', NavigationWorkspace::current());
        $this->assertTrue(\App\Support\OpticalNavigation::active('optical.orders'));
    }

    public function test_workspace_templates_compile_to_valid_php(): void
    {
        foreach (['layouts.admin.aside-admin', 'layouts.optical', 'components.navigation-workspace',
            'layouts.partials.optical-navigation', 'layouts.doctor.aside-doctor',
            'layouts.secretary.aside-secretary', 'components.navbar'] as $view) {
            $compiled = app('blade.compiler')->compileString(file_get_contents(view()->getFinder()->find($view)));
            $this->assertNotEmpty(token_get_all($compiled, TOKEN_PARSE), $view);
        }
    }

    public function test_unlicensed_area_is_not_a_workspace_choice(): void
    {
        $this->mock(ClinicAccessService::class, function ($mock) {
            $mock->shouldReceive('access')->with('clinical')->andReturn(['allowed' => true]);
            $mock->shouldReceive('access')->with('optical')->andReturn(['allowed' => false]);
        });
        $this->assertArrayNotHasKey('optical', NavigationWorkspace::available($this->staff('Super Admin')));
    }
}
