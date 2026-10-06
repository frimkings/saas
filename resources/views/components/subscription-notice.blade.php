{{-- Trial ending, overdue or suspended subscription, as one line above the page (hosted only). --}}
@auth
@if(config('tenancy.enabled'))
@php
    $subscriptionNotice = null;
    try {
        $tenantContext = app(\App\Support\Tenancy\TenantContext::class);
        if ($tenantContext->resolved()) {
            $activeSubscription = app(\App\Services\SubscriptionService::class)->current($tenantContext->requireClinic());
            if ($activeSubscription?->status === 'trial') {
                $days = max(0, now()->diffInDays($activeSubscription->trial_ends_at, false));
                if ($days <= 7) $subscriptionNotice = ['warning', "Trial ends in {$days} day(s). Choose a plan to avoid interruption."];
            } elseif ($activeSubscription?->status === 'overdue') {
                $days = max(0, now()->diffInDays($activeSubscription->grace_ends_at, false));
                $subscriptionNotice = ['warning', "Subscription payment is overdue. {$days} grace day(s) remain."];
            } elseif ($activeSubscription?->status === 'restricted') {
                $subscriptionNotice = ['danger', 'Subscription expired. The clinic is in read-only mode; existing records remain available.'];
            } elseif (in_array($activeSubscription?->status, ['suspended','cancelled','expired'], true)) {
                $subscriptionNotice = ['danger', 'Clinic subscription is suspended. Contact the platform administrator to restore access.'];
            }
        }
    } catch (\Throwable) {}
@endphp
@if($subscriptionNotice)<div class="alert alert-{{ $subscriptionNotice[0] }} rounded-0 mb-0 py-2 text-center"><i class="fas fa-exclamation-triangle mr-1"></i>{{ $subscriptionNotice[1] }} @hasrole('Super Admin')<a href="{{ route('admin.subscription') }}" class="alert-link ml-2">Open Subscription &amp; Billing</a>@endhasrole</div>@endif
@endif
@endauth
