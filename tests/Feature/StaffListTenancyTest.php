<?php

namespace Tests\Feature;

use App\Livewire\Admin\AuditTrailViewerComponent;
use App\Livewire\Admin\LoginHistoryComponent;
use App\Livewire\RefundLogsComponent;
use App\Livewire\StaffMessagingComponent;
use App\Models\AuditTrail;
use App\Models\Clinic;
use App\Models\LoginLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** Users are shared across clinics, so every staff list must show only this clinic's people. */
class StaffListTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $admin;
    private User $colleague;
    private User $outsider;
    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        Role::findOrCreate('Super Admin', 'web');

        $this->admin = User::factory()->create(['name' => 'Ama Admin']);
        $this->admin->assignRole('Super Admin');
        $this->colleague = User::factory()->create(['name' => 'Kofi Colleague', 'email' => 'kofi@here.test']);
        $this->outsider = User::factory()->create(['name' => 'Zed Outsider', 'email' => 'zed@elsewhere.test']);

        [$this->clinic, $branch] = $this->clinicWith('here', [$this->admin, $this->colleague]);
        [$other, $otherBranch] = $this->clinicWith('elsewhere', [$this->outsider]);

        // The outsider has history in their own clinic only.
        $context = app(TenantContext::class);
        $context->set($this->outsider, $other, $otherBranch, [$otherBranch->id]);
        LoginLog::recordFor($this->outsider);
        AuditTrail::record('report.accessed', 'Elsewhere activity', force: true);

        $context->set($this->admin, $this->clinic, $branch, [$branch->id]);
        LoginLog::recordFor($this->colleague);
        AuditTrail::record('report.accessed', 'Here activity', force: true);
        $this->actingAs($this->admin);
    }

    private function clinicWith(string $slug, array $users): array
    {
        $clinic = $this->activeClinic(['name' => ucfirst($slug), 'slug' => $slug]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        foreach ($users as $user) {
            $clinic->users()->attach($user, ['status' => 'active']);
            $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        }

        return [$clinic, $branch];
    }

    public function test_scope_keeps_only_this_clinics_staff(): void
    {
        $names = User::inCurrentClinic()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Ama Admin', 'Kofi Colleague'], $names);
    }

    public function test_login_history_lists_only_this_clinics_people_and_logins(): void
    {
        Livewire::test(LoginHistoryComponent::class)
            ->assertSee('kofi@here.test')
            ->assertDontSee('zed@elsewhere.test')
            ->assertDontSee('Zed Outsider');
    }

    public function test_audit_trail_lists_only_this_clinics_people_and_events(): void
    {
        Livewire::test(AuditTrailViewerComponent::class)
            ->assertSee('Here activity')
            ->assertDontSee('Elsewhere activity')
            ->assertDontSee('Zed Outsider');
    }

    public function test_staff_messages_cannot_reach_another_clinic(): void
    {
        Livewire::test(StaffMessagingComponent::class)
            ->assertSee('Kofi Colleague')
            ->assertDontSee('Zed Outsider')
            ->set('recipientId', $this->outsider->id)
            ->set('composeSubject', 'Hi')
            ->set('composeBody', 'Hello')
            ->call('sendMessage')
            ->assertHasErrors('recipientId');
    }

    public function test_refund_log_staff_filter_lists_only_this_clinic(): void
    {
        Livewire::test(RefundLogsComponent::class)->assertDontSee('Zed Outsider');
    }
}
