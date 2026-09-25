<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentSchedulePolicyTest extends TestCase
{
    public function test_hosted_and_offline_backup_policies_are_separated(): void
    {
        $kernel = file_get_contents(dirname(__DIR__, 2).'/app/Console/Kernel.php');

        $this->assertStringContainsString("if (config('tenancy.enabled'))", $kernel);
        $this->assertStringContainsString("->hourly()->withoutOverlapping()", $kernel);
        $this->assertStringContainsString("elseif (LicenseService::has(Feature::SCHEDULED_BACKUPS))", $kernel);
        $this->assertStringContainsString("->everyFiveMinutes()->withoutOverlapping()", $kernel);
    }
}
