<?php

namespace App\Services;

use App\Models\{Clinic,PatientDocument};
use Illuminate\Validation\ValidationException;

class SubscriptionQuotaService
{
    public function usage(Clinic $clinic): array
    {
        $subscription=app(SubscriptionService::class)->current($clinic);$plan=$subscription?->plan_snapshot??[];
        if (!app(ClinicAccessService::class)->hosted($clinic)) {
            $subscription = null;
            $plan = [];
        }
        return [
            'branches'=>['used'=>$clinic->branches()->where('is_active',true)->count(),'limit'=>$subscription?->branchLimit()],
            'users'=>['used'=>$clinic->users()->wherePivot('status','active')->count(),'limit'=>data_get($plan,'included_users')],
            'storage_mb'=>['used'=>(float)PatientDocument::query()->where('clinic_id',$clinic->id)->sum('file_size')/1048576,'limit'=>data_get($plan,'storage_limit_mb')],
        ];
    }

    public function assertUserAvailable(Clinic $clinic): void { $this->assertAvailable($this->usage($clinic)['users'],1,'user'); }
    public function assertStorageAvailable(Clinic $clinic, int $incomingBytes): void { $this->assertAvailable($this->usage($clinic)['storage_mb'],$incomingBytes/1048576,'storage'); }

    private function assertAvailable(array $quota, float $increase, string $label): void
    {
        if($quota['limit']!==null && $quota['used']+$increase>(float)$quota['limit']) throw ValidationException::withMessages(['subscription'=>"The subscription {$label} allowance has been reached. Upgrade the clinic plan to continue."]);
    }
}
