<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReconcileMigrationHistoryCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dry_run_does_not_change_migration_history(): void
    {
        $before = DB::table('migrations')->count();

        $exitCode = Artisan::call('schema:reconcile-migrations');

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertStringContainsString('Dry run only', Artisan::output());
    }

    public function test_duplicate_consultation_notes_rollback_is_a_no_op(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_000001_create_consultation_notes_table.php'
        );

        $migration->down();

        $this->assertTrue(Schema::hasTable('consultation_notes'));
    }
}
