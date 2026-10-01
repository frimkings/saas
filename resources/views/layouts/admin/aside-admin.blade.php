<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
<x-profile />

@php
    $navigationWorkspace = \App\Support\NavigationWorkspace::current();
@endphp
@include('components.navigation-workspace')
@if($navigationWorkspace === 'optical')
    @include('layouts.partials.optical-navigation')
@else

   

      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">

          @if($navigationWorkspace === 'administration')
          <li class="nav-item"><a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"><i class="nav-icon fas fa-tachometer-alt"></i><p>Business Overview</p></a></li>
          @else
          <li class="nav-item"><a href="{{ route('admin.clinical-task-center') }}" class="nav-link {{ request()->routeIs('admin.clinical-task-center') ? 'active' : '' }}"><i class="nav-icon fas fa-clipboard-check"></i><p>Clinical Task Center</p></a></li>
          <li class="nav-item"><a href="{{ route('secretary.patients') }}" class="nav-link"><i class="nav-icon fas fa-users"></i><p>Patients</p></a></li>
          <li class="nav-item"><a href="{{ route('secretary.appointments') }}" class="nav-link"><i class="nav-icon fas fa-calendar-alt"></i><p>Appointments</p></a></li>
          @endif

          @if($navigationWorkspace === 'clinical')
          {{-- Clinical --}}
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-stethoscope"></i>
              <p>Clinical <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              @hasanyrole('Doctor|Super Admin')
              <li class="nav-item">
                <a href="{{ route('doctor.patient-awaiting') }}" class="nav-link {{ request()->is('doctor/patient-awaiting') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>Patients Awaiting</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('doctor.all-records') }}" class="nav-link {{ request()->is('doctor/allrecords') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>All Records</p>
                </a>
              </li>
              @endhasanyrole
              <li class="nav-item">
                <a href="{{ route('admin.diagnoses') }}" class="nav-link {{ request()->is('admin/diagnoses') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>Diagnoses</p>
                </a>
              </li>
            </ul>
          </li>

          {{-- Inventory --}}
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-boxes"></i>
              <p>Clinical Inventory <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="{{ route('admin.category') }}" class="nav-link {{ request()->is('admin/category') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>Categories</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.product') }}" class="nav-link {{ request()->is('admin/product') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>Products</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.stock-movements') }}" class="nav-link {{ request()->is('admin/stock-movements') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-success"></i><p>Stock Receiving</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.stock-transfers') }}" class="nav-link {{ request()->is('admin/stock-transfers') ? 'active' : '' }}">
                  <i class="fas fa-exchange-alt nav-icon text-info"></i><p>Stock Transfers</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.inventory-alerts') }}" class="nav-link {{ request()->is('admin/inventory-alerts') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-warning"></i><p>Inventory Alerts</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.suppliers') }}" class="nav-link {{ request()->is('admin/suppliers') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>Suppliers</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.purchase-orders') }}" class="nav-link {{ request()->is('admin/purchase-orders') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-success"></i><p>Purchase Orders</p>
                </a>
              </li>
            </ul>
          </li>

          @endif
          @if($navigationWorkspace === 'administration')
          {{-- Finances (an optical-only subscriber gets the optical pages that do the same job) --}}
          @php $opticalOnlyFinance = \App\Support\OpticalMode::opticalOnly(); @endphp
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>Finances <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="{{ $opticalOnlyFinance ? route('optical.reports') : route('admin.reports') }}" class="nav-link {{ request()->is('admin/reports') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>{{ \App\Support\FinanceStatements::hasBothLines() ? 'Clinic Sales Reports' : 'Sales Reports' }}</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ $opticalOnlyFinance ? route('optical.sales') : route('cashier.sales-records') }}" class="nav-link {{ request()->is('cashier/sales-records') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-success"></i><p>Sales Records</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ $opticalOnlyFinance ? route('optical.profit') : route('admin.income-statement') }}" class="nav-link {{ request()->is('admin/income-statement*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-success"></i><p>{{ $opticalOnlyFinance ? 'Profit & Loss' : 'Income Statement' }}</p>
                </a>
              </li>
              @if(\App\Support\FinanceStatements::canViewCombined(auth()->user()))
              <li class="nav-item">
                <a href="{{ route('admin.combined-statement') }}" class="nav-link {{ request()->is('admin/combined-statement*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-primary"></i><p>Combined Statement</p>
                </a>
              </li>
              @endif
              <li class="nav-item">
                <a href="{{ $opticalOnlyFinance ? route('optical.expenses') : route('admin.expenses') }}" class="nav-link {{ request()->is('admin/expenses*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-danger"></i><p>Expenses</p>
                </a>
              </li>
              @unless($opticalOnlyFinance)
              <li class="nav-item">
                <a href="{{ route('admin.daily-cash-summary') }}" class="nav-link {{ request()->is('admin/daily-cash-summary*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>Daily Cash Summary</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('cashier.outstanding-balances') }}" class="nav-link {{ request()->is('cashier/outstanding-balances*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-warning"></i><p>Outstanding Balances</p>
                </a>
              </li>
              @endunless
              <li class="nav-item">
                <a href="{{ route('admin.lens-outstanding-report') }}" class="nav-link">
                  <i class="far fa-circle nav-icon text-warning"></i><p>Lens Outstanding PDF</p>
                </a>
              </li>
              @unless($opticalOnlyFinance)
              <li class="nav-item">
                <a href="{{ route('admin.quotations') }}" class="nav-link {{ request()->is('admin/quotations') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-primary"></i><p>Quotations</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.patient-ledger') }}" class="nav-link {{ request()->is('admin/patient-ledger') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>Patient Ledger</p>
                </a>
              </li>
              @endunless
              <li class="nav-item">
                <a href="{{ route('admin.approvals') }}" class="nav-link {{ request()->is('admin/approvals*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-warning"></i>
                  <p>
                    Approvals
                    @php
                      $totalPending = \App\Support\ApprovalCounts::total();
                    @endphp
                    @if($totalPending > 0)
                      <span class="badge badge-warning right">{{ $totalPending }}</span>
                    @endif
                  </p>
                </a>
              </li>
            </ul>
          </li>

          @endif
          @if($navigationWorkspace === 'clinical')
          {{-- Insurance --}}
          <li class="nav-item">
            <a href="#" class="nav-link {{ request()->is('admin/insurance*') ? 'active' : '' }}">
              <i class="nav-icon fas fa-shield-alt"></i>
              <p>Insurance <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="{{ route('admin.insurance.claims') }}" class="nav-link {{ request()->is('admin/insurance/claims*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-primary"></i><p>Claims</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.insurance.insurers') }}" class="nav-link {{ request()->is('admin/insurance/insurers*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>Insurers</p>
                </a>
              </li>
            </ul>
          </li>

          @endif
          {{-- Communications --}}
          <li class="nav-item">
            <a href="#" class="nav-link {{ request()->is('admin/sms-logs', 'admin/patient-recall') ? 'active' : '' }}">
              <i class="nav-icon fas fa-comments"></i>
              <p>Communications <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="{{ route('admin.sms-logs') }}" class="nav-link {{ request()->is('admin/sms-logs') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>SMS Logs</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.patient-recall') }}" class="nav-link {{ request()->is('admin/patient-recall') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-success"></i><p>Patient Recall</p>
                </a>
              </li>
            </ul>
          </li>

          @if($navigationWorkspace === 'administration')
          {{-- Staff & Security --}}
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-users-cog"></i>
              <p>Staff &amp; Security <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              @hasrole('Super Admin')
              <li class="nav-item">
                <a href="{{ route('admin.users') }}" class="nav-link {{ request()->is('admin/users') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>Users</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.roles-permissions') }}" class="nav-link {{ request()->is('admin/roles-permissions') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-primary"></i><p>Roles &amp; Permissions</p>
                </a>
              </li>
              @endhasrole
              <li class="nav-item">
                <a href="{{ route('admin.login-history') }}" class="nav-link {{ request()->is('admin/login-history') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>Login History</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.audit-trail') }}" class="nav-link {{ request()->is('admin/audit-trail') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-warning"></i><p>Audit Trail</p>
                </a>
              </li>
            </ul>
          </li>

          {{-- System (Super Admin only) --}}
          @hasrole('Super Admin')
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-cog"></i>
              <p>System <i class="right fas fa-angle-left"></i></p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="{{ route('admin.settings') }}" class="nav-link {{ request()->is('admin/settings*') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon"></i><p>Settings</p>
                </a>
              </li>
              <li class="nav-item"><a href="{{ route('admin.backups') }}" class="nav-link"><i class="fas fa-database nav-icon"></i><p>Backups</p></a></li>
              <li class="nav-item">
                <a href="{{ route('admin.branches') }}" class="nav-link {{ request()->is('admin/branches*') ? 'active' : '' }}">
                  <i class="fas fa-code-branch nav-icon text-info"></i><p>Branches</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.offline-health') }}" class="nav-link {{ request()->is('admin/offline-health') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-success"></i><p>Offline Health</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="{{ route('admin.license') }}" class="nav-link {{ request()->is('admin/license') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-warning"></i><p>License &amp; Subscription</p>
                </a>
              </li>
              @if(app(\App\Services\ClinicAccessService::class)->hosted())
              <li class="nav-item">
                <a href="{{ route('admin.subscription') }}" class="nav-link {{ request()->is('admin/subscription*') ? 'active' : '' }}">
                  <i class="fas fa-credit-card nav-icon text-success"></i><p>Subscription &amp; Billing</p>
                </a>
              </li>
              @endif
              {{-- Archive Manager — uncomment once route is registered --}}
              {{-- <li class="nav-item">
                <a href="{{ route('admin.archive-manager') }}" class="nav-link {{ request()->is('admin/archive-manager') ? 'active' : '' }}">
                  <i class="far fa-circle nav-icon text-info"></i><p>Archive Manager</p>
                </a>
              </li> --}}
            </ul>
          </li>
          @endhasrole

          @endif
        </ul>
      </nav>

@endif
  </aside>
