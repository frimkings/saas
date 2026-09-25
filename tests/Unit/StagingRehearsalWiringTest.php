<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StagingRehearsalWiringTest extends TestCase
{
    public function test_rehearsal_covers_release_and_recovery_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/StagingRehearsalService.php');
        $command = file_get_contents($root.'/app/Console/Commands/StagingRehearsalCommand.php');

        foreach (['production_readiness', 'routes', 'schema', 'transaction', 'scheduler', 'backup_integrity', 'public_storage', 'config_cache'] as $check) {
            $this->assertStringContainsString("'{$check}'", $service);
        }
        $this->assertStringContainsString('platform:staging-rehearsal', $command);
        $this->assertStringContainsString('non-destructive', strtolower($command));
    }
}
