<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupSchedulerCommand extends Command
{
    protected $signature   = 'setup:scheduler';
    protected $description = 'Register the Laravel scheduler as a Windows Task Scheduler task so backups and other scheduled jobs run automatically.';

    const TASK_NAME         = 'Laravel Scheduler – Eye Clinic';
    const STARTUP_TASK_NAME = 'Laravel Backup – Eye Clinic (Startup)';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->error('This command only works on Windows. On Linux/Mac add a cron entry: * * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1');
            return 1;
        }

        $phpBinary  = PHP_BINARY;
        $artisanPath = base_path('artisan');

        $this->ensureBackupStorageExists();

        if ($this->taskExists()) {
            $this->info('Task "' . self::TASK_NAME . '" already exists in Windows Task Scheduler.');
            $this->line('  Run <comment>schtasks /Query /TN "' . self::TASK_NAME . '"</comment> to inspect it.');
            return 0;
        }

        $this->info('Registering Windows Task Scheduler task...');

        // Command the task runs: php artisan schedule:run (output suppressed)
        $tr = "\"{$phpBinary}\" \"{$artisanPath}\" schedule:run";

        $cmd = 'schtasks /Create /F'
            . ' /TN "' . self::TASK_NAME . '"'
            . ' /SC MINUTE /MO 1'
            . ' /TR "' . $tr . '"'
            . ' /RL HIGHEST';

        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('Failed to create the task. Output:');
            foreach ($output as $line) {
                $this->line("  $line");
            }
            $this->line('');
            $this->line('You may need to run this command from an elevated (Administrator) terminal.');
            return 1;
        }

        $this->info('Task registered successfully.');
        $this->line('  The scheduler will now run every minute.');
        $this->line('  Backups will start appearing in <comment>storage/app/backups/</comment> within 5 minutes.');
        $this->line('');
        $this->line('  Verify: <comment>schtasks /Query /TN "' . self::TASK_NAME . '"</comment>');

        $this->registerStartupBackupTask($phpBinary, $artisanPath);

        return 0;
    }

    private function registerStartupBackupTask(string $phpBinary, string $artisanPath): void
    {
        if ($this->startupTaskExists()) {
            $this->info('Startup backup task already exists.');
            return;
        }

        $this->info('Registering startup backup task...');

        // Run a full backup 1 minute after boot so MySQL/Apache have time to start
        $tr  = "\"{$phpBinary}\" \"{$artisanPath}\" backup:run";
        $cmd = 'schtasks /Create /F'
            . ' /TN "' . self::STARTUP_TASK_NAME . '"'
            . ' /SC ONSTART'
            . ' /DELAY 0001:00'
            . ' /TR "' . $tr . '"'
            . ' /RL HIGHEST';

        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->warn('Could not register startup backup task. Output:');
            foreach ($output as $line) {
                $this->line("  $line");
            }
            return;
        }

        $this->info('Startup backup task registered.');
        $this->line('  A full backup will run automatically 1 minute after every boot.');
        $this->line('  Verify: <comment>schtasks /Query /TN "' . self::STARTUP_TASK_NAME . '"</comment>');
    }

    private function taskExists(): bool
    {
        exec('schtasks /Query /TN "' . self::TASK_NAME . '" 2>&1', $output, $exitCode);
        return $exitCode === 0;
    }

    private function startupTaskExists(): bool
    {
        exec('schtasks /Query /TN "' . self::STARTUP_TASK_NAME . '" 2>&1', $output, $exitCode);
        return $exitCode === 0;
    }

    private function ensureBackupStorageExists(): void
    {
        $path = storage_path('app/backups');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $this->line("Created directory: {$path}");
        }
    }
}
