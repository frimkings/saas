<?php
namespace App\Console\Commands;
use App\Services\DeploymentReadinessService;
use Illuminate\Console\Command;
class DeploymentReadinessCommand extends Command {
 protected $signature='platform:deployment-readiness {--json : Output machine-readable JSON} {--no-persist : Do not save this run} {--strict : Treat warnings as a failing exit code}';protected $description='Audit production configuration and runtime readiness before clinic cutover.';
 public function handle(DeploymentReadinessService $service):int{$report=$service->run(!$this->option('no-persist'),null);if($this->option('json')){$this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}else{$this->newLine();$this->info('EyeClinic Production Deployment Readiness');$this->table(['Category','Check','Result','Severity','Detail'],collect($report['checks'])->map(fn($c)=>[$c['category'],$c['label'],$c['passed']?'PASS':'FAIL',strtoupper($c['severity']),$c['detail']])->all());$summary=$report['summary'];$this->{$report['ready']?'info':'error'}(strtoupper($report['status'])." - {$summary['passed']} passed, {$summary['warnings']} warning(s), {$summary['failed']} critical failure(s).");if(!$report['ready']){$this->newLine();$this->warn('Required remediation:');foreach($report['checks'] as $c)if(!$c['passed'])$this->line('- '.$c['label'].': '.$c['remediation']);}}return !$report['ready']||($this->option('strict')&&$report['summary']['warnings']>0)?self::FAILURE:self::SUCCESS;}
}
