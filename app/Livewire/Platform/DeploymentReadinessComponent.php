<?php
namespace App\Livewire\Platform;
use App\Models\DeploymentReadinessRun;
use App\Services\DeploymentReadinessService;
use App\Services\PlatformAuditService;
use Livewire\Component;
class DeploymentReadinessComponent extends Component {
 public function runAudit(DeploymentReadinessService $service,PlatformAuditService $audit):void{$report=$service->run(true,(int)auth()->id());$audit->record('PLATFORM_DEPLOYMENT_READINESS_RUN',null,[],['status'=>$report['status'],'summary'=>$report['summary']]);session()->flash('readiness_message',$report['ready']?'Deployment readiness passed.':'Deployment remains blocked. Review the failed checks.');}
 public function render(){return view('livewire.platform.deployment-readiness-component',['latest'=>DeploymentReadinessRun::with('operator')->latest('id')->first(),'history'=>DeploymentReadinessRun::with('operator')->latest('id')->limit(20)->get()])->layout('layouts.platform');}
}
