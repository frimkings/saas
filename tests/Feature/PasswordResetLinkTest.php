<?php

namespace Tests\Feature;

use App\Livewire\Admin\PasswordResetApprovalsComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\{Clinic, PasswordResetRequest, User};
use App\Support\ApprovalCounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * An approved reset emails a one-time link to the staff member's own inbox. The link (or the
 * manager's code) is what lets someone set the password: the approval alone never does.
 */
class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a', 'deployment_mode' => 'hosted']);
        $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
    }

    private function member(string $role): User
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

    /** Approve a reset as the clinic's Super Admin and return the link token from the email. */
    private function approveAndCatchLink(User $staff): array
    {
        app(TenantContext::class)->clear();
        $request = PasswordResetRequest::create(['email' => $staff->email, 'status' => 'pending']);

        $admin = $this->member('Super Admin');
        $branch = $this->clinic->branches()->first();
        app(TenantContext::class)->set($admin, $this->clinic, $branch, [$branch->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($admin, $branch);
        ApprovalCounts::forget();
        $this->actingAs($admin);

        $page = Livewire::test(PasswordResetApprovalsComponent::class)
            ->call('openConfirm', $request->id, 'approve')->call('execute')
            ->assertSee('emailed them a one-time link');
        $this->assertSame('sent', $page->get('linkStatus'));

        $url = null;
        Mail::assertSent(OwnerNoticeMail::class, function (OwnerNoticeMail $mail) use ($staff, &$url) {
            $url = $mail->buttonUrl;
            return $mail->hasTo($staff->email);
        });
        $this->assertNotNull($url);

        auth()->logout();
        app(TenantContext::class)->clear();
        $this->flushSession();

        return [$request->fresh(), $url, (string) $page->get('issuedCode')];
    }

    public function test_the_emailed_link_lets_the_owner_set_a_password_without_the_code(): void
    {
        Mail::fake();
        $staff = $this->member('Doctor');
        [$request, $url] = $this->approveAndCatchLink($staff);

        $this->assertNotNull($request->link_hash);
        $this->assertStringNotContainsString($request->link_hash, $url, 'only the hash is stored');

        $this->get($url)->assertRedirect(route('password.offline.form'));
        $this->get(route('password.offline.form'))->assertOk()->assertSee('You opened the link from your email')->assertDontSee('Reset code from your manager');

        $this->post(route('password.offline.update'), ['password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewPass123', $staff->fresh()->password));
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertNull($request->fresh()->link_hash);

        // Used once: the same link no longer works.
        $this->flushSession();
        $this->get($url)->assertRedirect(route('password.request'));
    }

    public function test_typing_the_email_alone_still_needs_the_code(): void
    {
        Mail::fake();
        $staff = $this->member('Doctor');
        [$request] = $this->approveAndCatchLink($staff);

        // Someone who only knows the email reaches the form but cannot finish without the code.
        $this->post('/forgot-password', ['email' => $staff->email])->assertRedirect(route('password.offline.form'));
        $this->get(route('password.offline.form'))->assertSee('Reset code from your manager');
        $this->post(route('password.offline.update'), ['password' => 'Hijack123', 'password_confirmation' => 'Hijack123'])
            ->assertSessionHasErrors('code');
        $this->post(route('password.offline.update'), ['code' => '000000', 'password' => 'Hijack123', 'password_confirmation' => 'Hijack123'])
            ->assertSessionHasErrors('code');
        $this->assertFalse(Hash::check('Hijack123', $staff->fresh()->password));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_expired_or_rejected_links_do_not_open(): void
    {
        Mail::fake();
        $staff = $this->member('Doctor');
        [$request, $url] = $this->approveAndCatchLink($staff);

        $this->travel(PasswordResetRequest::LINK_MINUTES + 1)->minutes();
        $this->get($url)->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->travelBack();

        $request->update(['status' => 'rejected']);
        $this->get($url)->assertRedirect(route('password.request'));
        $this->get('/reset-password/link/'.str_repeat('a', 64))->assertRedirect(route('password.request'));
    }
}
