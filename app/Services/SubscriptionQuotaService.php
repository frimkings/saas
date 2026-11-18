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
            'users'=>['used'=>$clinic->users()->wherePivot('status','active')->count(),'limit'=>$this->userLimit($clinic,$plan)],
            'attendance_staff'=>['used'=>\App\Support\AttendancePlan::followed($clinic),'limit'=>\App\Support\AttendancePlan::staffLimit($clinic)],
            'storage_mb'=>['used'=>(float)PatientDocument::query()->where('clinic_id',$clinic->id)->sum('file_size')/1048576,'limit'=>$this->storageLimitMb($clinic,$plan)],
        ];
    }

    public function assertUserAvailable(Clinic $clinic): void
    {
        $quota = $this->usage($clinic)['users'];
        if ($quota['limit'] !== null && $quota['used'] + 1 > $quota['limit']) {
            $price = (float) (app(ClinicAddonService::class)->catalogue($clinic)->get(\App\Support\Feature::EXTRA_USER)['price'] ?? 0);
            throw ValidationException::withMessages(['subscription' => "The clinic has used all {$quota['limit']} of its users. Ask your clinic's Super Admin to add an extra user"
                . ($price > 0 ? ' (' . number_format($price, 2) . ' a month each)' : '') . ' or upgrade the plan under Subscription.']);
        }
    }

    /** The plan's included users plus any extra users bought as an add-on; null = no limit. */
    public function userLimit(Clinic $clinic, ?array $plan = null): ?int
    {
        $plan ??= app(ClinicAccessService::class)->hosted($clinic) ? (app(SubscriptionService::class)->current($clinic)?->plan_snapshot ?? []) : [];
        $limit = data_get($plan, 'included_users');

        return $limit === null ? null : (int) $limit + app(ClinicAddonService::class)->extraUsers($clinic->id);
    }
    public function assertStorageAvailable(Clinic $clinic, int $incomingBytes): void
    {
        $quota = $this->usage($clinic)['storage_mb'];
        // A plan with no scan storage (0 MB) and no storage packs: say how to get it rather than "allowance reached".
        if ($quota['limit'] !== null && (float) $quota['limit'] <= 0) {
            throw ValidationException::withMessages(['subscription' => 'Scans and attachments come with the Standard plan and above, or with a scan storage pack. Ask your clinic\'s Super Admin to upgrade or add one under Subscription.']);
        }
        $this->assertAvailable($quota,$incomingBytes/1048576,'storage');
    }

    /** The plan's scan & attachment allowance plus any storage packs; null = no limit. */
    public function storageLimitMb(Clinic $clinic, ?array $plan = null): ?int
    {
        $plan ??= app(ClinicAccessService::class)->hosted($clinic) ? (app(SubscriptionService::class)->current($clinic)?->plan_snapshot ?? []) : [];
        $limit = data_get($plan, 'storage_limit_mb');

        return $limit === null ? null : (int) $limit + app(ClinicAddonService::class)->extraStorageMb($clinic->id);
    }

    private function assertAvailable(array $quota, float $increase, string $label): void
    {
        if($quota['limit']!==null && $quota['used']+$increase>(float)$quota['limit']) throw ValidationException::withMessages(['subscription'=>"The subscription {$label} allowance has been reached. Upgrade the clinic plan to continue."]);
    }
}
