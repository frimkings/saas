<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>

        <li class="nav-item d-none d-sm-inline-block">
        <a href="{{ route('user.profile')}}" class="nav-link primary">User Profile</a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        {{-- <a href="" class="nav-link primary">Admin</a> --}}
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit"  class="nav-link primary">
                {{ __('Logout') }}
            </button>
        </form>
      </li>

    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      @auth
      @if(config('tenancy.enabled'))
        @php
          $tenantContext = app(\App\Support\Tenancy\TenantContext::class);
          $activeClinicId = $tenantContext->clinicId();
          $activeBranchId = $tenantContext->branchId();
          $availableClinics = $tenantContext->availableClinics();
          $availableBranches = $tenantContext->availableBranches();
        @endphp
        @if(auth()->user()->is_platform_admin)
        <li class="nav-item mr-2"><form method="POST" action="{{ route('tenant.mode.switch') }}">@csrf<input type="hidden" name="mode" value="platform"><button class="btn btn-sm btn-outline-dark mt-1"><i class="fas fa-server mr-1"></i>Platform</button></form></li>
        @endif
        @if($availableClinics->count() > 1)
        <li class="nav-item mr-2"><a href="{{ route('tenant.clinic.select') }}" class="btn btn-sm btn-outline-primary mt-1"><i class="fas fa-hospital mr-1"></i>{{ $tenantContext->clinic()?->name }} <i class="fas fa-exchange-alt ml-1"></i></a></li>
        @endif
        @if($availableBranches->count() > 1)
        <li class="nav-item mr-2">
          <form method="POST" action="{{ route('tenant.branch.switch') }}">@csrf
            <select name="branch_id" class="form-control form-control-sm mt-1" onchange="this.form.submit()" aria-label="Active branch">
              @foreach($availableBranches as $branch)<option value="{{ $branch->id }}" @selected($activeBranchId === $branch->id)>{{ $branch->name }}</option>@endforeach
            </select>
          </form>
        </li>
        @endif
      @endif
      @endauth
      <!-- Navbar Search -->
      <li class="nav-item">
        <a class="nav-link" data-widget="navbar-search" href="#" role="button">
          <i class="fas fa-search"></i>
        </a>
        <div class="navbar-search-block">
          <form class="form-inline">
            <div class="input-group input-group-sm">
              <input class="form-control form-control-navbar" type="search" placeholder="Search" aria-label="Search">
              <div class="input-group-append">
                <button class="btn btn-navbar" type="submit">
                  <i class="fas fa-search"></i>
                </button>
                <button class="btn btn-navbar" type="button" data-widget="navbar-search">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
          </form>
        </div>
      </li>

      <!-- Messages Dropdown Menu -->
      @auth
      <livewire:staff-messages-dropdown-component />
      @endauth
      @auth
      <livewire:notification-bell-component />
      @endauth
      <li class="nav-item">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" data-widget="control-sidebar" data-slide="true" href="#" role="button">
          <i class="fas fa-th-large"></i>
        </a>
      </li>
    </ul>
    
  </nav>
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
