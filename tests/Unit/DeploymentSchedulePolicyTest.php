<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Behaviour is covered by Tests\Feature\HostedScheduleTest; this guards the policy's shape. */
class DeploymentSchedulePolicyTest extends TestCase
{
    public function test_hosted_and_offline_backup_policies_are_separated(): void
    {
        $kernel = file_get_contents(dirname(__DIR__, 2).'/app/Console/Kernel.php');

        // Hosted installs (tenancy on) schedule no app backups; only offline installs do.
        $this->assertStringContainsString("if (! config('tenancy.enabled') && LicenseService::has(Feature::SCHEDULED_BACKUPS))", $kernel);
        $this->assertStringContainsString("->everyFiveMinutes()->withoutOverlapping()", $kernel);
    }
}
