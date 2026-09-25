<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Clinic;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextSnapshot;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAsyncContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_payload_captures_and_restores_the_authorized_context(): void
    {
        [$user, $clinic, $branch] = $this->tenantUser('Async Clinic', 'async-clinic');
        $this->setContext($user, $clinic, $branch);

        $payload = json_decode(
            (new TenantPayloadProbeQueue)->tenantPayload(new TenantContextProbeJob),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertSame($user->id, $payload['tenant_context']['user_id']);
        $this->assertSame($clinic->id, $payload['tenant_context']['clinic_id']);
        $this->assertSame($branch->id, $payload['tenant_context']['branch_id']);

        app(TenantContext::class)->clear();
        TenantContextSnapshot::restore($payload['tenant_context']);

        $this->assertSame($clinic->id, app(TenantContext::class)->clinicId());
        $this->assertSame($branch->id, app(TenantContext::class)->branchId());
    }

    public function test_queued_context_is_rejected_after_membership_is_revoked(): void
    {
        [$user, $clinic, $branch] = $this->tenantUser('Revoked Clinic', 'revoked-clinic');
        $this->setContext($user, $clinic, $branch);
        $snapshot = TenantContextSnapshot::capture();

        DB::table('clinic_user')->where('clinic_id', $clinic->id)
            ->where('user_id', $user->id)->update(['status' => 'inactive']);
        app(TenantContext::class)->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no longer authorized');
        TenantContextSnapshot::restore($snapshot);
    }

    public function test_tenant_file_paths_fail_closed_and_include_tenant_uuids(): void
    {
        config()->set('tenancy.enabled', true);

        try {
            TenantStorage::branding();
            $this->fail('Tenant storage accepted a missing context.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Clinic context', $exception->getMessage());
        }

        [$user, $clinic, $branch] = $this->tenantUser('Files Clinic', 'files-clinic');
        $this->setContext($user, $clinic, $branch);

        $this->assertSame("clinics/{$clinic->uuid}/branding", TenantStorage::branding());
        $this->assertSame(
            "clinics/{$clinic->uuid}/branches/{$branch->uuid}/exports",
            TenantStorage::branch('exports')
        );
    }

    public function test_role_notifications_are_delivered_only_inside_the_active_branch(): void
    {
        [$sender, $clinic, $activeBranch] = $this->tenantUser('Notify Clinic', 'notify-clinic');
        $otherBranch = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other']);
        $activeRecipient = User::factory()->create();
        $otherRecipient = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Secretary', 'guard_name' => 'web']);

        foreach ([$activeRecipient, $otherRecipient] as $recipient) {
            $clinic->users()->attach($recipient, ['status' => 'active']);
        }
        $activeBranch->users()->attach($activeRecipient, ['status' => 'active']);
        $otherBranch->users()->attach($otherRecipient, ['status' => 'active']);
        DB::table('branch_user_role')->insert([
            ['branch_id' => $activeBranch->id, 'user_id' => $activeRecipient->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $otherBranch->id, 'user_id' => $otherRecipient->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->setContext($sender, $clinic, $activeBranch);

        NotificationService::sendToRoles(['Secretary'], 'test', 'Tenant alert', 'Active branch only');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $activeRecipient->id,
            'clinic_id' => $clinic->id,
            'branch_id' => $activeBranch->id,
        ]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $otherRecipient->id]);
    }

    public function test_notification_reads_are_restricted_to_the_active_branch(): void
    {
        [$user, $clinic, $activeBranch] = $this->tenantUser('Inbox Clinic', 'inbox-clinic');
        $otherBranch = $clinic->branches()->create(['code' => 'OTHER', 'name' => 'Other']);
        $otherBranch->users()->attach($user, ['status' => 'active']);

        DB::table('app_notifications')->insert([
            ['clinic_id' => $clinic->id, 'branch_id' => $activeBranch->id, 'user_id' => $user->id, 'type' => 'test', 'title' => 'Visible', 'body' => 'Visible', 'created_at' => now(), 'updated_at' => now()],
            ['clinic_id' => $clinic->id, 'branch_id' => $otherBranch->id, 'user_id' => $user->id, 'type' => 'test', 'title' => 'Hidden', 'body' => 'Hidden', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->setContext($user, $clinic, $activeBranch, [$activeBranch->id, $otherBranch->id]);

        $this->assertSame(['Visible'], AppNotification::pluck('title')->all());
    }

    private function tenantUser(string $name, string $slug): array
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        $clinic = Clinic::create(['name' => $name, 'slug' => $slug]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);

        return [$user, $clinic, $branch];
    }

    private function setContext(User $user, Clinic $clinic, $branch, ?array $branchIds = null): void
    {
        app(TenantContext::class)->set($user, $clinic, $branch, $branchIds ?? [$branch->id]);
    }
}

class TenantContextProbeJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function handle(): void
    {
    }
}

class TenantPayloadProbeQueue extends SyncQueue
{
    public function tenantPayload(object $job): string
    {
        return $this->createPayload($job, 'default');
    }
}
