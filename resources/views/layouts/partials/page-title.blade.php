{{-- The browser tab title: page name (by route) then clinic name. --}}
@php
    $routeTitles = [
        // Admin
        'admin.dashboard'               => 'Admin Dashboard',
        'admin.clinical-task-center'    => 'Clinical Task Center',
        'admin.offline-health'          => 'Offline Health Dashboard',
        'admin.category'                => 'Categories',
        'admin.product'                 => 'Products',
        'admin.suppliers'               => 'Suppliers',
        'admin.settings'                => 'Settings',
        'admin.users'                   => 'User Management',
        'admin.reports'                 => 'Reports',
        'admin.sales-records'           => 'Sales Records',
        'admin.income-statement'        => 'Income Statement',
        'admin.diagnoses'               => 'Diagnoses',
        'admin.inventory-alerts'        => 'Inventory Alerts',
        'admin.stock-movements'         => 'Stock Movements',
        'admin.daily-cash-summary'      => 'Daily Cash Summary',
        'admin.audit-trail'             => 'Audit Trail',
        'admin.login-history'           => 'Login History',
        'admin.discount-approvals'      => 'Discount Approvals',
        'admin.approvals'                      => 'Approvals',
        'admin.refund-approvals'               => 'Refund Approvals',
        'admin.clearance-revoke-approvals'    => 'Clearance Revoke Approvals',
        'admin.password-reset-approvals'      => 'Password Reset Approvals',
        'admin.roles-permissions'       => 'Roles & Permissions',

        // Secretary
        'secretary.dashboard'           => 'Reception',
        'secretary.patients'            => 'Patients',
        'secretary.appointments'        => 'Appointments',
        'secretary.spectacles'          => 'Spectacles Orders',
        'secretary.patient-clearance'   => 'Patient Clearance',

        // Cashier / POS
        'cashier.seller-desk'           => 'Point of Sale',
        'cashier.outstanding-balances'  => 'Outstanding Balances',
        'cashier.sales-records'         => 'Sales Records',
        'cashier.receipt.show'          => 'Receipt',
        'refunds.logs'                  => 'Refund Logs',
        'cart'                          => 'Cart',

        // Doctor
        'doctor.dashboard'              => 'Doctor Dashboard',
        'doctor.patient-awaiting'       => 'Patients Awaiting',
        'doctor.patient-records'        => 'Patient Records',
        'doctor.all-records'            => 'All Records',
        'doctor.referrals'              => 'Referrals',
        'doctor.patient-timeline'       => 'Patient Timeline',

        // Shared
        'user.profile'                  => 'My Profile',
        'staff.messages'                => 'Messages',
        'dashboard'                     => 'Dashboard',
    ];
    $pageTitle = $routeTitles[\Illuminate\Support\Facades\Route::currentRouteName() ?? ''] ?? null;
    try {
        $clinicName = \App\Models\Setting::getSettings()->clinic_name ?? config('app.name');
    } catch (\Throwable $e) {
        $clinicName = config('app.name');
    }
@endphp
<title>{{ $pageTitle ? $pageTitle . ' - ' : '' }}{{ $clinicName }}</title>
