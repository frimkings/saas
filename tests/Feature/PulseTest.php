<?php

namespace Tests\Feature;

use App\Models\{AppNotification, Clinic, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** The single page poll: each user gets only the parts they may see, and polling isn't activity. */
class PulseTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->clinic = $this->activeClinic(['name' => 'Pulse Clinic', 'slug' => 'pulse-clinic', 'deployment_mode' => 'hosted']);
        $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $branch = $this->clinic->branches()->first();
        $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $role = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_manager_gets_badges_and_discount_notice_but_not_doctor_clearance(): void
    {
        $manager = $this->staff('Manager');

        $this->actingAs($manager)->getJson('/pulse?parts=notifications,messages,discount,clearance')
            ->assertOk()
            ->assertJsonStructure(['notifications', 'messages'])
            ->assertJsonPath('discount', null)
            ->assertJsonMissingPath('clearance');
    }

    public function test_doctor_gets_clearance_notice_but_not_discount_prompt(): void
    {
        $doctor = $this->staff('Doctor');

        $this->actingAs($doctor)->getJson('/pulse?parts=discount,clearance')
            ->assertOk()
            ->assertJsonMissingPath('discount')
            ->assertJsonPath('clearance.pending_count', 0);
    }

    public function test_only_requested_parts_are_returned_and_unknown_ones_ignored(): void
    {
        $cashier = $this->staff('Cashier');
        $branch = $this->clinic->branches()->first();
        app(TenantContext::class)->set($cashier, $this->clinic, $branch, [$branch->id]);
        AppNotification::create(['user_id' => $cashier->id, 'type' => 'info', 'title' => 'Hello', 'body' => 'Hi']);

        $this->actingAs($cashier)->getJson('/pulse?parts=notifications,bogus')
            ->assertOk()
            ->assertExactJson(['notifications' => 1]);
    }

    public function test_a_poll_is_not_activity_unless_the_user_was_active(): void
    {
        $user = $this->staff('Cashier');
        $idleSince = time() - 10 * 60;

        $this->actingAs($user)->withSession(['auth.last_activity_at' => $idleSince])
            ->getJson('/pulse?parts=notifications')->assertOk();
        $this->assertSame($idleSince, session('auth.last_activity_at'));

        $this->getJson('/pulse?parts=notifications&active=1')->assertOk();
        $this->assertGreaterThan(time() - 5, session('auth.last_activity_at'));
    }

    public function test_an_idle_tab_is_logged_out_by_its_poll(): void
    {
        $user = $this->staff('Cashier');

        $this->actingAs($user)->withSession(['auth.last_activity_at' => time() - 31 * 60])
            ->getJson('/pulse?parts=notifications')
            ->assertStatus(419);
        $this->assertGuest();
    }
}
