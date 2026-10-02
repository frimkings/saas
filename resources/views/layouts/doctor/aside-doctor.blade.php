<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <x-profile />
    @include('components.navigation-workspace')

    <div class="sidebar">
    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">

        {{-- Dashboard --}}
        <li class="nav-item">
          <a href="{{ route('doctor.dashboard') }}" class="nav-link {{ request()->is('doctor/dashboard') ? 'active' : '' }}">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Dashboard</p>
          </a>
        </li>

        {{-- Needs attention: today's reminders, including expiring stock --}}
        @php $attentionCounts = app(\App\Services\Reminders\AttentionItems::class)->counts('clinic'); @endphp
        <li class="nav-item">
          <a href="{{ route('attention') }}" class="nav-link {{ request()->routeIs('attention*') ? 'active' : '' }}">
            <i class="nav-icon fas fa-bell"></i>
            <p>Needs Attention @if($attentionCounts['total'])<span class="badge badge-danger right">{{ $attentionCounts['total'] }}</span>@endif</p>
          </a>
        </li>

        {{-- Clinical --}}
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-stethoscope"></i>
            <p>Clinical <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="{{ route('doctor.patient-awaiting') }}" class="nav-link {{ request()->is('doctor/patient-awaiting') ? 'active' : '' }}">
                <i class="far fa-circle nav-icon text-success"></i><p>Patient Queue</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="{{ route('doctor.all-records') }}" class="nav-link {{ request()->is('doctor/allrecords') ? 'active' : '' }}">
                <i class="far fa-circle nav-icon"></i><p>All Records</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="{{ route('doctor.referrals') }}" class="nav-link {{ request()->is('doctor/referrals*') ? 'active' : '' }}">
                <i class="far fa-circle nav-icon text-info"></i><p>Referrals</p>
              </a>
            </li>
          </ul>
        </li>

      </ul>
    </nav>
    <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>
