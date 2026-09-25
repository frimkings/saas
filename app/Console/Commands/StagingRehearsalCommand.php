<?php

namespace App\Console\Commands;

use App\Services\StagingRehearsalService;
use Illuminate\Console\Command;

class StagingRehearsalCommand extends Command
{
    protected $signature = 'platform:staging-rehearsal {--json : Output machine-readable JSON} {--strict : Treat warnings as failure}';
    protected $description = 'Run a non-destructive staging deployment and recovery-readiness rehearsal.';

    public function handle(StagingRehearsalService $service): int
    {
        $report = $service->run();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('EyeClinic Staging Deployment Rehearsal');
            $this->table(['Check', 'Result', 'Severity', 'Detail'], collect($report['checks'])->map(fn ($check) => [
                $check['label'], $check['passed'] ? 'PASS' : 'FAIL', strtoupper($check['severity']), $check['detail'],
            ])->all());
            $this->line($report['recovery_instruction']);
        }

        return ! $report['passed'] || ($this->option('strict') && $report['summary']['warnings'] > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
