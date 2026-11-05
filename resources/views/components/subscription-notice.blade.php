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
                // Whole calendar days (Carbon 3 returns fractions: "3.1477784807639 day(s)").
                $ends = $activeSubscription->trial_ends_at;
                $days = $ends ? max(0, (int) now()->startOfDay()->diffInDays($ends->copy()->startOfDay(), false)) : 0;
                $when = match (true) { $days === 0 => 'today', $days === 1 => 'tomorrow', default => "in {$days} days" };
                if ($days <= 7) $subscriptionNotice = ['warning', "Trial ends {$when}" . ($ends ? ', on ' . $ends->format('d M Y') : '') . '. Choose a plan to avoid interruption.'];
            } elseif ($activeSubscription?->status === 'overdue') {
                $days = $activeSubscription->grace_ends_at ? max(0, (int) now()->startOfDay()->diffInDays($activeSubscription->grace_ends_at->copy()->startOfDay(), false)) : 0;
                $subscriptionNotice = ['warning', 'Subscription payment is overdue. ' . ($days ? $days . ' grace ' . \Illuminate\Support\Str::plural('day', $days) . ' remain.' : 'The grace period ends today.')];
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
