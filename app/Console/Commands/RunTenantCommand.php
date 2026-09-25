<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RunTenantCommand extends Command
{
    protected $signature = 'tenancy:run-scheduled {tenantCommand} {--clinic-only}';
    protected $description = 'Run a scheduled application command once for every active tenant scope.';

    public function handle(): int
    {
        $command = (string) $this->argument('tenantCommand');
        abort_if($command === $this->getName(), 400, 'Recursive tenant command is not allowed.');
        $query = Branch::query()->where('is_active', true)->whereHas('clinic', fn ($q) => $q->where('status', 'active'));
        if ($this->option('clinic-only')) {
            $query->where('is_default', true);
        }

        $failed = 0;
        $query->with('clinic')->orderBy('clinic_id')->orderBy('id')->each(function (Branch $branch) use ($command, &$failed) {
            $user = $branch->users()->wherePivot('status', 'active')->orderBy('users.id')->first();
            if (! $user) {
                $this->warn("Skipped {$branch->clinic->name} / {$branch->name}: no active staff membership.");
                return;
            }
            $branchIds = $user->branches()->where('branches.clinic_id', $branch->clinic_id)
                ->wherePivot('status', 'active')->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
            app(TenantContext::class)->set($user, $branch->clinic, $branch, $branchIds);
            try {
                $access = app(\App\Services\ClinicAccessService::class)->access();
                if (!$access['allowed'] || $access['read_only']) {
                    $this->warn("Skipped {$branch->clinic->name}: license or subscription is read-only.");
                    return;
                }
                $exit = Artisan::call($command);
                $this->output->write(Artisan::output());
                $failed += $exit === self::SUCCESS ? 0 : 1;
            } finally {
                app(TenantContext::class)->clear();
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
