<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** logs:prune keeps login history 30 days and audit events 30 days (views/exports) or a year (changes), for every clinic. */
class LogRetentionTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private array $clinics = [];
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = User::factory()->create()->id;
        foreach (['one', 'two'] as $slug) {
            $this->clinics[] = $this->activeClinic(['name' => ucfirst($slug), 'slug' => $slug])->id;
        }
    }

    private int $archiveId = 900000;

    /** Archive tables keep the copied row's id, so they have no auto-increment. */
    private function idFor(string $table): array
    {
        return str_ends_with($table, '_archive') ? ['id' => ++$this->archiveId] : [];
    }

    private function login(string $table, int $daysAgo, int $clinicId): void
    {
        DB::table($table)->insert($this->idFor($table) + ['clinic_id' => $clinicId, 'user_id' => $this->userId, 'login_at' => now()->subDays($daysAgo)]);
    }

    private function audit(string $table, string $event, int $daysAgo, int $clinicId): void
    {
        DB::table($table)->insert($this->idFor($table) + ['clinic_id' => $clinicId, 'event' => $event, 'description' => $event . ' ' . $daysAgo,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
    }

    public function test_old_logins_and_audit_events_are_deleted_for_every_clinic(): void
    {
        foreach ($this->clinics as $clinicId) {
            $this->login('login_logs', 5, $clinicId);
            $this->login('login_logs', 31, $clinicId);
            $this->audit('audit_trails', 'report.accessed', 5, $clinicId);
            $this->audit('audit_trails', 'report.accessed', 31, $clinicId);
            $this->audit('audit_trails', 'patient.exported', 31, $clinicId);
            $this->audit('audit_trails', 'expense.deleted', 31, $clinicId);
            $this->audit('audit_trails', 'expense.deleted', 366, $clinicId);
        }
        $this->login('login_logs_archive', 120, $this->clinics[0]);
        $this->audit('audit_trails_archive', 'insurer.updated', 400, $this->clinics[0]);

        $this->artisan('logs:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(4, DB::table('login_logs')->count(), 'A dry run changes nothing.');

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertSame([5, 5], DB::table('login_logs')->orderBy('clinic_id')->get()
            ->map(fn ($r) => (int) round(now()->diffInDays($r->login_at, true)))->all());
        $this->assertEqualsCanonicalizing(
            ['report.accessed 5', 'expense.deleted 31', 'report.accessed 5', 'expense.deleted 31'],
            DB::table('audit_trails')->pluck('description')->all(),
            'Views and exports go after 30 days; changes stay for a year.'
        );
        $this->assertSame(0, DB::table('login_logs_archive')->count());
        $this->assertSame(0, DB::table('audit_trails_archive')->count());
    }
}
