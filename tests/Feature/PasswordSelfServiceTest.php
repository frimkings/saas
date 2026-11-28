<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserRoleManagerComponent;
use App\Mail\OwnerNoticeMail;
use App\Models\{Clinic, PasswordResetRequest, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/**
 * Online, "Forgot password" emails a one-time link at once (the owner is told); a manager can email
 * one from the Staff page. A password someone else chose (new staff, CSV, a manager's reset) must be
 * changed at the next sign-in, and existing staff who never chose their own are asked once.
 */
class PasswordSelfServiceTest extends TestCase
{
    use RefreshDatabase, GivesClinicsAccess;

    private const OWNER = 'owner@clinic-a.test';

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['tenancy.enabled' => true]);
        $this->clinic = $this->activeClinic(['name' => 'Clinic A', 'slug' => 'clinic-a', 'deployment_mode' => 'hosted']);
        $this->clinic->update(['billing_email' => self::OWNER]);
        $this->clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
    }

    private function member(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $branch = $this->clinic->branches()->first();
        $this->clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $role = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function signIn(User $user): void
    {
        $branch = $this->clinic->branches()->first();
        app(TenantContext::class)->set($user, $this->clinic, $branch, [$branch->id]);
        app(\App\Support\Tenancy\BranchRoleManager::class)->hydrate($user, $branch);
        $this->actingAs($user);
    }

    /** @return OwnerNoticeMail[] */
    private function sentTo(string $address): array
    {
        return Mail::sent(OwnerNoticeMail::class, fn ($mail) => $mail->hasTo($address))->values()->all();
    }

    public function test_forgot_password_emails_a_link_at_once_and_tells_the_owner(): void
    {
        $staff = $this->member('Doctor', ['name' => 'Kofi Doctor', 'email' => 'kofi@clinic-a.test']);
        $staff->forceFill(['must_change_password' => true])->save();

        $this->post('/forgot-password', ['email' => 'kofi@clinic-a.test'])->assertSessionHas('request_status', 'emailed');
        // An address with no account gets the same answer and no email.
        $this->post('/forgot-password', ['email' => 'nobody@clinic-a.test'])->assertSessionHas('request_status', 'emailed');

        $mail = $this->sentTo('kofi@clinic-a.test');
        $this->assertCount(1, $mail);
        $this->assertSame('Reset your password', $mail[0]->heading);
        $owner = $this->sentTo(self::OWNER);
        $this->assertCount(1, $owner);
        $this->assertSame('Password reset link sent', $owner[0]->heading);
        $this->assertSame(0, PasswordResetRequest::pendingCount(), 'nothing waits for approval');

        // Asking again at once sends nothing more.
        $this->post('/forgot-password', ['email' => 'kofi@clinic-a.test']);
        $this->assertCount(1, $this->sentTo('kofi@clinic-a.test'));

        // The link sets the password; they chose it, so no change screen after.
        $this->get($mail[0]->buttonUrl)->assertRedirect(route('password.offline.form'));
        $this->post(route('password.offline.update'), ['password' => 'MyOwn1234', 'password_confirmation' => 'MyOwn1234'])->assertRedirect(route('login'));
        $staff->refresh();
        $this->assertTrue(Hash::check('MyOwn1234', $staff->password));
        $this->assertFalse($staff->must_change_password);
        $this->assertNotNull($staff->last_password_changed_at);
    }

    public function test_a_newer_link_replaces_the_older_one(): void
    {
        $staff = $this->member('Doctor', ['email' => 'kofi@clinic-a.test']);
        $this->post('/forgot-password', ['email' => 'kofi@clinic-a.test']);
        $first = $this->sentTo('kofi@clinic-a.test')[0]->buttonUrl;

        $this->travel(PasswordResetLinksTestMinutes::AFTER)->minutes();
        $this->post('/forgot-password', ['email' => 'kofi@clinic-a.test']);
        $mails = $this->sentTo('kofi@clinic-a.test');
        $this->assertCount(2, $mails);

        $this->get($first)->assertRedirect(route('password.request'));
        $this->flushSession();
        $this->get($mails[1]->buttonUrl)->assertRedirect(route('password.offline.form'));
    }

    public function test_offline_installs_keep_the_managers_approval(): void
    {
        config(['tenancy.enabled' => false]);
        $this->member('Doctor', ['email' => 'kofi@clinic-a.test']);

        $this->post('/forgot-password', ['email' => 'kofi@clinic-a.test'])->assertSessionHas('request_status', 'submitted');
        $this->assertSame('pending', PasswordResetRequest::sole()->status);
        $this->assertCount(0, $this->sentTo('kofi@clinic-a.test'));
    }

    public function test_the_staff_page_emails_a_link_and_a_password_a_manager_sets_must_be_changed(): void
    {
        $owner = $this->member('Super Admin');
        $staff = $this->member('Cashier', ['email' => 'ama@clinic-a.test']);
        $this->signIn($owner);

        Livewire::test(UserRoleManagerComponent::class)->call('openResetPassword', $staff->id)
            ->assertSee('Email a reset link')->call('emailResetLink')->assertHasNoErrors();
        $this->assertCount(1, $this->sentTo('ama@clinic-a.test'));
        $this->assertCount(0, $this->sentTo(self::OWNER), 'the owner sent it themselves');
        $this->assertFalse($staff->fresh()->must_change_password);

        Livewire::test(UserRoleManagerComponent::class)->call('openResetPassword', $staff->id)
            ->set('newPassword', 'Start123')->set('newPasswordConfirmation', 'Start123')->call('doResetPassword')->assertHasNoErrors();
        $this->assertTrue($staff->fresh()->must_change_password);

        // Signing in with it goes to the change screen first.
        auth()->logout();
        app(TenantContext::class)->clear();
        $this->post('/login', ['login' => 'ama@clinic-a.test', 'password' => 'Start123'])->assertRedirect(route('password.force.edit'));
    }

    public function test_new_staff_from_the_form_or_a_csv_must_change_their_starting_password(): void
    {
        $owner = $this->member('Super Admin');
        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $this->signIn($owner);
        $main = $this->clinic->branches()->first();

        Livewire::test(UserRoleManagerComponent::class)
            ->set('name', 'New Cashier')->set('email', 'new.cashier@clinic-a.test')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->set('selectedRoles', ['Cashier'])->set('selectedBranchIds', [$main->id])->set('defaultBranchId', $main->id)
            ->set('membershipStatus', 'active')->call('store')->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'new.cashier@clinic-a.test')->sole()->must_change_password);

        $csv = "name,email,password,role,phone,staff_id,gender,date_of_birth,department,hire_date\nYaw Boateng,yaw@clinic-a.test,secret123,Cashier,,,,,,\n";
        Livewire::test(UserRoleManagerComponent::class)->set('importFile', UploadedFile::fake()->createWithContent('staff.csv', $csv))->call('importCsv');
        $this->assertTrue(User::where('email', 'yaw@clinic-a.test')->sole()->must_change_password);

        // The owner editing their own record with a new password is not asked again.
        Livewire::test(UserRoleManagerComponent::class)->call('edit', $owner->id)
            ->set('password', 'OwnerNew123')->set('password_confirmation', 'OwnerNew123')->call('store')->assertHasNoErrors();
        $this->assertFalse($owner->fresh()->must_change_password);
    }

    public function test_existing_staff_who_never_chose_their_own_password_are_asked_once(): void
    {
        $never = $this->member('Cashier');
        $changed = $this->member('Doctor');
        $changed->forceFill(['last_password_changed_at' => now()->subWeek()])->save();
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $notStaff = User::factory()->create();

        (require database_path('migrations/2026_11_28_000002_require_password_change_for_existing_staff.php'))->up();

        $this->assertTrue($never->fresh()->must_change_password);
        $this->assertFalse($changed->fresh()->must_change_password);
        $this->assertFalse($platform->fresh()->must_change_password);
        $this->assertFalse($notStaff->fresh()->must_change_password);
    }
}

/** Long enough after a link that asking again sends a new one. */
final class PasswordResetLinksTestMinutes
{
    public const AFTER = \App\Services\PasswordResetLinks::RESEND_AFTER_MINUTES + 1;
}
