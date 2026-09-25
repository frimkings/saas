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
        <a href="{{ route('secretary.dashboard') }}"
          class="nav-link {{ request()->is('secretary/dashboard') ? 'active' : '' }}">
          <i class="nav-icon fas fa-tachometer-alt"></i>
          <p>Dashboard</p>
        </a>
      </li>

      {{-- Patients --}}
      <li class="nav-item">
        <a href="#" class="nav-link">
          <i class="nav-icon fas fa-users"></i>
          <p>Patients <i class="right fas fa-angle-left"></i></p>
        </a>
        <ul class="nav nav-treeview">
          <li class="nav-item">
            <a href="{{ route('secretary.patients') }}"
              class="nav-link {{ request()->is('secretary/patients') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon"></i><p>Registry Hub</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="{{ route('secretary.appointments') }}"
              class="nav-link {{ request()->routeIs('secretary.appointments') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon text-info"></i><p>Appointments</p>
            </a>
          </li>
        </ul>
      </li>

      {{-- Clearance & Spectacles --}}
      <li class="nav-item">
        <a href="#" class="nav-link">
          <i class="nav-icon fas fa-clipboard-check"></i>
          <p>Clearance &amp; Spectacles <i class="right fas fa-angle-left"></i></p>
        </a>
        <ul class="nav nav-treeview">
          <li class="nav-item">
            <a href="{{ route('secretary.patient-clearance') }}"
              class="nav-link {{ request()->is('secretary/patient-clearance') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon"></i><p>Patient Clearance</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="{{ route('secretary.spectacles') }}"
              class="nav-link {{ request()->is('secretary/spectacles') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon text-info"></i><p>Spectacles</p>
            </a>
          </li>
        </ul>
      </li>

      {{-- Sales & Billing --}}
      <li class="nav-item">
        <a href="#" class="nav-link">
          <i class="nav-icon fas fa-cash-register"></i>
          <p>Sales &amp; Billing <i class="right fas fa-angle-left"></i></p>
        </a>
        <ul class="nav nav-treeview">
          <li class="nav-item">
            <a href="{{ route('cashier.seller-desk') }}"
              class="nav-link {{ request()->is('cashier/seller-desk') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon text-success"></i><p>Point of Sale</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="{{ route('cashier.sales-records') }}"
              class="nav-link {{ request()->is('cashier/sales-records') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon"></i><p>Sales Records</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="{{ route('cashier.outstanding-balances') }}"
              class="nav-link {{ request()->is('cashier/outstanding-balances*') ? 'active' : '' }}">
              <i class="far fa-circle nav-icon text-warning"></i><p>Outstanding Balances</p>
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
