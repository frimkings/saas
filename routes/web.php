<?php

if (app()->environment('local')) {
    Route::view('/design-system', 'design-system.showcase')->name('design-system.showcase');
}

use App\Http\Controllers\Administrator\AdminDashboardController;
use App\Http\Controllers\Cashier\ReceiptController;
use App\Http\Controllers\Doctor\DoctorDashboardController;
use App\Http\Controllers\Secretary\SecretaryDashboardController;
use App\Livewire\Admin\CategoryComponent;
use App\Livewire\Admin\ClinicalTaskCenterComponent;
use App\Livewire\Admin\OfflineHealthDashboardComponent;
use App\Livewire\Admin\ProductsComponent;
use App\Livewire\CartComponent;
use App\Livewire\Cashier\CashierDashboardComponent;
use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Livewire\Cashier\SalesRecordsComponent;
use App\Livewire\Cashier\SellerDeskComponent;
use App\Livewire\Doctor\AllrecordsComponent;
use App\Livewire\Doctor\PatientAwaitingComponent;
use App\Livewire\Doctor\PatientRecordsComponent;
use App\Livewire\Doctor\ShowConsultationComponent;
use App\Livewire\Doctor\UpdateConsultationComponent;
use App\Livewire\Doctor\UsersComponent;
use App\Livewire\POSComponent;
use App\Livewire\OutstandingBalancesComponent;
use App\Livewire\RefundLogsComponent;
use App\Livewire\ReportsComponent;
use App\Livewire\Secretary\AppointmentsComponent;
use App\Livewire\Secretary\PatientsComponent;
use App\Livewire\Secretary\SpectaclesComponent;
use App\Livewire\Admin\UserRoleManagerComponent;
use App\Livewire\Admin\RolePermissionManagerComponent;
use App\Livewire\Admin\PasswordResetApprovalsComponent;
use App\Livewire\Admin\DiscountApprovalsComponent;
use App\Livewire\Admin\RefundApprovalsComponent;
use App\Livewire\Admin\ClearanceRevokeApprovalsComponent;
use App\Livewire\Admin\AllApprovalsComponent;
use App\Livewire\Admin\AuditTrailViewerComponent;
use App\Livewire\Admin\LicenseComponent;
use App\Livewire\Admin\DailyCashSummaryComponent;
use App\Livewire\Admin\InventoryAlertsComponent;
use App\Livewire\Admin\LoginHistoryComponent;
use App\Livewire\Admin\StockMovementComponent;
use App\Models\Spectacles;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Doctor\PatientMedicalRecordController;
use App\Http\Controllers\DiscountApprovalNoticeController;
use App\Http\Controllers\Doctor\ReferralController;
use App\Http\Controllers\IncomeStatementExportController;
use App\Livewire\Doctor\ReferralComponent;
use App\Livewire\StaffMessagingComponent;
use App\Livewire\Admin\AdminSettingsComponent;
use App\Livewire\Admin\BackupManagerComponent;
use App\Livewire\Admin\SmsLogsComponent;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\TenantContextController;
use App\Http\Controllers\PatientDocumentController;
use App\Http\Controllers\ClinicContextController;
use App\Http\Controllers\WorkspaceModeController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect()->route('login');
});

// Never redirect to login here: guests are bounced back to HOME (/dashboard), which loops
// for signed-in users without a dashboard role. The resolver falls back to the profile page.
Route::get('/dashboard', function () {
    return redirect()->to(app(\App\Support\Tenancy\RoleDashboardResolver::class)->url(auth()->user()));
})->middleware(['auth'])->name('dashboard');

require __DIR__.'/auth.php';

Route::middleware(['auth', 'platform.admin'])->group(function () {
    Route::get('/platform', \App\Livewire\Platform\PlatformDashboardComponent::class)->name('platform.dashboard');
    Route::get('/platform/imports', \App\Livewire\Platform\LegacyImportManagerComponent::class)->name('platform.imports');
    Route::get('/platform/deployment-readiness', \App\Livewire\Platform\DeploymentReadinessComponent::class)->name('platform.deployment-readiness');
    Route::get('/platform/sms', \App\Livewire\Platform\SmsMessagingComponent::class)->name('platform.sms');
    Route::get('/platform/support', \App\Livewire\Platform\SupportSettingsComponent::class)->name('platform.support');
    Route::get('/platform/announcements', \App\Livewire\Platform\AnnouncementsComponent::class)->name('platform.announcements');
    Route::get('/platform/usage', \App\Livewire\Platform\ClinicUsageComponent::class)->name('platform.usage');
    Route::get('/platform/subscription-analytics',\App\Livewire\Platform\SubscriptionAnalyticsComponent::class)->name('platform.subscription-analytics');
    Route::get('/platform/subscription-analytics.csv', [\App\Http\Controllers\Platform\SubscriptionAnalyticsExportController::class,'csv'])->name('platform.subscription-analytics.csv');
    Route::get('/platform/billing-report.csv', [\App\Http\Controllers\Platform\BillingReportController::class,'csv'])->name('platform.billing-report.csv');
    Route::get('/platform/billing-report.pdf', [\App\Http\Controllers\Platform\BillingReportController::class,'pdf'])->name('platform.billing-report.pdf');
    Route::get('/platform/invoices/{invoice}.pdf', [\App\Http\Controllers\Platform\InvoiceDocumentController::class,'invoice'])->name('platform.invoice.pdf');
    Route::get('/platform/invoices/{invoice}/receipt.pdf', [\App\Http\Controllers\Platform\InvoiceDocumentController::class,'receipt'])->name('platform.invoice.receipt');
    Route::get('/platform/imports/{batch}/validation.csv', [\App\Http\Controllers\Platform\LegacyImportReportController::class,'csv'])->name('platform.imports.validation.csv');
    Route::get('/platform/imports/{batch}/validation.pdf', [\App\Http\Controllers\Platform\LegacyImportReportController::class,'pdf'])->name('platform.imports.validation.pdf');
    Route::get('/platform/imports/{batch}/evidence.json', [\App\Http\Controllers\Platform\LegacyImportReportController::class,'evidence'])->name('platform.imports.evidence');
});


Route::middleware(['auth'])->group(function () {
    Route::get('/subscription/locked', \App\Http\Controllers\SubscriptionLockedController::class)->name('subscription.locked');
    Route::post('/context/navigation-workspace', \App\Http\Controllers\NavigationWorkspaceController::class)->name('navigation.workspace');
    // "Needs attention": today's reminders for clinic staff (everyone sees everything).
    Route::get('/attention', \App\Livewire\AttentionPanelComponent::class)->name('attention');
    Route::get('/attention/old-orders', \App\Livewire\OldOrdersComponent::class)->name('attention.old-orders');
    // A platform announcement banner the signed-in Super Admin has read.
    Route::post('/announcements/{announcement}/dismiss', function (\App\Models\PlatformAnnouncement $announcement) {
        app(\App\Services\Platform\Announcements::class)->dismiss($announcement, auth()->user());
        return response()->noContent();
    })->name('announcements.dismiss');
    Route::get('/context/mode', [WorkspaceModeController::class, 'select'])->name('tenant.mode.select');
    Route::post('/context/mode', [WorkspaceModeController::class, 'switch'])->name('tenant.mode.switch');
    Route::get('/context/clinic', [ClinicContextController::class, 'select'])->name('tenant.clinic.select');
    Route::post('/context/clinic', [ClinicContextController::class, 'switch'])->name('tenant.clinic.switch');
    Route::post('/context/branch', [TenantContextController::class, 'switchBranch'])->name('tenant.branch.switch');
    Route::get('/patient-documents/{document}', [PatientDocumentController::class, 'show'])->name('patient-documents.show');
    Route::get('/profile', 'App\Livewire\UserProfileComponent')->name('user.profile');

    // The one endpoint open pages poll: badge counts and approval/clearance notices.
    Route::get('/pulse', \App\Http\Controllers\PulseController::class)->name('pulse');

    Route::get('/messages', StaffMessagingComponent::class)->name('staff.messages');
});




//secretary

// Added the pipe | between Manager and Super Admin
Route::middleware(['auth', 'role:Secretary|Manager|Super Admin'])->group(function () {
   Route::get('secretary/dashboard', SecretaryDashboardController::class)->name('secretary.dashboard');

    Route::get('secretary/patients', PatientsComponent::class)->name('secretary.patients');
    Route::get('secretary/appointments', AppointmentsComponent::class)->name('secretary.appointments')->middleware('feature:appointments');
    Route::get('secretary/spectacles', SpectaclesComponent::class)->name('secretary.spectacles');
Route::get('secretary/patient-clearance', CashierPatientClearanceComponent::class)->name('secretary.patient-clearance');


});




Route::middleware(['auth', 'role:Secretary|Cashier|Manager|Super Admin'])->group(function () {
 //cashier
Route::get('cashier/dashboard', CashierDashboardComponent::class)->name('cashier.dashboard');
Route::get('cashier/seller-desk', POSComponent::class)->name('cashier.seller-desk');
});




//secretary

// Added the pipe | between Manager and Super Admin
Route::middleware(['auth', 'role:Secretary|Manager|Super Admin'])->group(function () {
   Route::get('secretary/dashboard', SecretaryDashboardController::class)->name('secretary.dashboard');

    Route::get('secretary/patients', PatientsComponent::class)->name('secretary.patients');
    Route::get('secretary/appointments', AppointmentsComponent::class)->name('secretary.appointments')->middleware('feature:appointments');
    Route::get('secretary/spectacles', SpectaclesComponent::class)->name('secretary.spectacles');
Route::get('secretary/patient-clearance', CashierPatientClearanceComponent::class)->name('secretary.patient-clearance');


});




Route::middleware(['auth', 'role:Secretary|Cashier|Optician|Manager|Super Admin'])->group(function () {
 //cashier
Route::get('cashier/dashboard', CashierDashboardComponent::class)->name('cashier.dashboard');
Route::get('cashier/seller-desk', POSComponent::class)->name('cashier.seller-desk');
Route::get('cashier/outstanding-balances', OutstandingBalancesComponent::class)->name('cashier.outstanding-balances')->middleware('feature:outstanding_balances');
Route::get('/livewire/ajax-patients', [POSComponent::class, 'getPatientsJson']);
Route::get('/cashier/sales-records', SalesRecordsComponent::class)->name('cashier.sales-records');
Route::get('/cashier/refund-logs', RefundLogsComponent::class)->name('refunds.logs');
Route::get('/cart', CartComponent::class)->name('cart');

// FIXED: Changed from /receipt/{saleId} to /cashier/receipt/{saleId}
Route::get('/cashier/receipt/{saleId}', function (int $saleId, \Illuminate\Http\Request $request) {
    \App\Models\Sales::clinicSales()->findOrFail($saleId);
    return app(ReceiptController::class)->show($saleId, $request);
})
    ->name('cashier.receipt.show');
// One receipt per visit (interim until the bill is settled).
Route::get('/cashier/visit-receipt/{visit}', [\App\Http\Controllers\Cashier\VisitReceiptController::class, 'show'])->name('cashier.visit-receipt.show');
Route::get('/cashier/visit-receipt/sale/{saleId}', [\App\Http\Controllers\Cashier\VisitReceiptController::class, 'forSale'])->whereNumber('saleId')->name('cashier.visit-receipt.sale');
Route::get('/cashier/receipt/{saleId}/pdf', function (int $saleId, \Illuminate\Http\Request $request) {
    \App\Models\Sales::clinicSales()->findOrFail($saleId);
    return app(ReceiptController::class)->downloadPdf($saleId, $request);
})
    ->name('cashier.receipt.pdf');
Route::get('/cashier/refund-receipt/{refund}', [\App\Http\Controllers\RefundReceiptController::class, 'show'])
    ->name('refunds.receipt');
Route::get('/cashier/refund-receipt/{refund}/pdf', [\App\Http\Controllers\RefundReceiptController::class, 'downloadPdf'])
    ->name('refunds.receipt.pdf');

// Printable clearance receipt; CashierPatientClearance is branch-scoped, so other branches' records 404.
Route::get('/cashier/clearance-receipt/{id}', function ($id) {
    $clearance = \App\Models\CashierPatientClearance::with(['patient', 'service', 'user', 'sale'])->findOrFail($id);
    $clinicSettings = \App\Models\Setting::getSettings();
    return view('cashier.clearance-receipt', compact('clearance', 'clinicSettings'));
})->name('cashier.clearance-receipt');

});

// Optical Module Routes. optical.access opens each screen to the roles it belongs to (App\Support\OpticalAccess),
// so the optical roles reach optical screens without the cashier pages above.
Route::middleware(['auth', 'feature:optical', 'optical.access'])->group(function () {
Route::get('/optical/dashboard', \App\Livewire\Optical\OpticalDashboardComponent::class)->name('optical.dashboard');
Route::get('/optical/partners', \App\Livewire\Optical\PartnerClinicsComponent::class)->name('optical.partners');
Route::get('/optical/partners/{partner}/statement', \App\Livewire\Optical\PartnerStatementComponent::class)->name('optical.partners.statement');
Route::get('/optical/partners/{partner}/statement/print', function (int $partner, \Illuminate\Http\Request $request) {
    $partner = \App\Models\OpticalPartnerClinic::findOrFail($partner);
    $service = app(\App\Services\OpticalPartnerAccountService::class);
    $fromDate = \Illuminate\Support\Carbon::parse($request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString());
    $toDate = \Illuminate\Support\Carbon::parse($request->date('to')?->toDateString() ?? now()->toDateString());
    if ($fromDate->gt($toDate)) [$fromDate, $toDate] = [$toDate, $fromDate];
    return view('optical.partner-statement', [
        'partner' => $partner, 'fromDate' => $fromDate, 'toDate' => $toDate,
        'statement' => $service->statement($partner, $fromDate, $toDate),
        'balance' => $service->balance($partner), 'aging' => $service->aging($partner),
    ]);
})->name('optical.partners.statement.print');
Route::redirect('/optical/customers', '/optical/partners')->name('optical.customers');
Route::get('/optical/prescriptions', \App\Livewire\Optical\OpticalPrescriptionsComponent::class)->name('optical.prescriptions');
Route::get('/optical/catalogue', \App\Livewire\Optical\OpticalCatalogueComponent::class)->name('optical.catalogue');
Route::get('/optical/categories', \App\Livewire\Optical\OpticalCategoriesComponent::class)->name('optical.categories');
 Route::get('/optical/products', \App\Livewire\Optical\OpticalProductsComponent::class)->name('optical.products');
 Route::get('/optical/stock', \App\Livewire\Optical\OpticalStockManagementComponent::class)->name('optical.stock');
 Route::get('/optical/stock/receive-lenses', \App\Livewire\Optical\OpticalLensReceivingComponent::class)->middleware('role:Manager|Super Admin')->name('optical.stock.receive-lenses');
Route::get('/optical/orders/create', \App\Livewire\Optical\OpticalOrderCreateComponent::class)->name('optical.orders.create');
Route::get('/optical/orders/{order}/docket', function (int $order) {
    $order = \App\Models\LensOrder::with(['patient', 'opticalPrescription', 'frameProduct', 'lensProduct', 'frameOpticalProduct', 'lensOpticalProduct', 'user'])->findOrFail($order);
    return view('optical.order-docket', compact('order'));
})->name('optical.orders.docket');
Route::get('/optical/orders', \App\Livewire\Optical\OpticalOrdersComponent::class)->name('optical.orders');
Route::get('/optical/lab-workbench', \App\Livewire\Optical\OpticalLabWorkbenchComponent::class)->name('optical.lab-workbench');
Route::get('/optical/lab-workbench/print', fn (\Illuminate\Http\Request $request) => \App\Livewire\Optical\OpticalLabWorkbenchComponent::printSheet($request))->name('optical.lab-workbench.print');
Route::get('/optical/collections', \App\Livewire\Optical\OpticalCollectionsComponent::class)->name('optical.collections');
Route::get('/optical/attention', \App\Livewire\AttentionPanelComponent::class)->name('optical.attention');
Route::get('/optical/attention/old-orders', \App\Livewire\OldOrdersComponent::class)->name('optical.attention.old-orders');
Route::get('/optical/jobs', \App\Livewire\Optical\OpticalJobsComponent::class)->name('optical.jobs');
Route::get('/optical/stock-counts', \App\Livewire\Optical\OpticalStockCountsComponent::class)->name('optical.stock-counts');
Route::middleware('role:Manager|Super Admin')->group(function () {
    Route::get('/optical/purchasing', \App\Livewire\Optical\OpticalPurchasingComponent::class)->name('optical.purchasing');
    Route::get('/optical/expenses', \App\Livewire\Admin\ExpensesComponent::class)->name('optical.expenses');
    Route::get('/optical/expenses/receipt/{expense}', function (\App\Models\Expense $expense) {
        abort_unless($expense->business_line === \App\Models\Expense::OPTICAL, 404);
        abort_unless($expense->receipt_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($expense->receipt_path), 404);
        return \Illuminate\Support\Facades\Storage::disk('public')->download($expense->receipt_path, basename($expense->receipt_path), ['Content-Disposition' => 'inline']);
    })->name('optical.expenses.receipt');
    Route::get('/optical/profit', \App\Livewire\Optical\OpticalProfitComponent::class)->name('optical.profit');
    Route::get('/optical/profit/export/{format}', \App\Http\Controllers\OpticalProfitExportController::class)->whereIn('format', ['csv', 'pdf'])->name('optical.profit.export');
    Route::get('/optical/purchasing/{order}/print', function (int $order) {
        $order = \App\Models\OpticalPurchaseOrder::with(['supplier', 'lines'])->findOrFail($order);
        return view('optical.purchase-order', compact('order'));
    })->name('optical.purchasing.print');
});
Route::get('/optical/pos', \App\Livewire\Optical\OpticalPosComponent::class)->name('optical.pos');
Route::get('/optical/sales', SalesRecordsComponent::class)->name('optical.sales');
Route::get('/optical/receipt/{saleId}', function (int $saleId, \Illuminate\Http\Request $request) {
    \App\Models\Sales::query()->whereKey($saleId)->where('business_line', 'optical')->firstOrFail();

    return app(ReceiptController::class)->show($saleId, $request);
})->name('optical.receipt');
Route::get('/optical/reports', \App\Livewire\Optical\OpticalReportsComponent::class)->name('optical.reports');
Route::get('/optical/reports/{report}/{format}', \App\Http\Controllers\OpticalReportExportController::class)
    ->whereIn('report', array_keys(\App\Http\Controllers\OpticalReportExportController::REPORTS))->whereIn('format', ['print', 'pdf', 'csv'])->name('optical.reports.export');
Route::get('/optical/settings', \App\Livewire\Optical\OpticalSettingsComponent::class)->middleware('role:Manager|Super Admin')->name('optical.settings');
});

//admin


Route::middleware(['auth', 'role:Super Admin|Manager'])->group(function () {
 //admin
Route::get('/admin/reports', ReportsComponent::class)->name('admin.reports')->middleware('optical-only');
Route::get('/admin/subscription', \App\Livewire\Admin\ClinicSubscriptionPortalComponent::class)->middleware('role:Super Admin')->name('admin.subscription');
Route::get('/admin/subscription/invoices/{invoice}', [\App\Http\Controllers\ClinicBillingDocumentController::class,'invoice'])->middleware('role:Super Admin')->name('admin.subscription.invoice');
Route::get('/admin/subscription/receipts/{invoice}', [\App\Http\Controllers\ClinicBillingDocumentController::class,'receipt'])->middleware('role:Super Admin')->name('admin.subscription.receipt');
Route::get('/admin/sales-records', SalesRecordsComponent::class)->name('admin.sales-records');
Route::get('/admin/reports/export/pdf', [\App\Http\Controllers\ReportExportController::class, 'exportPdf'])->name('reports.export.pdf')->middleware('optical-only');
Route::get('/admin/income-statement', \App\Livewire\Admin\IncomeStatementComponent::class)->name('admin.income-statement')->middleware(['optical-only', 'feature:advanced_reports']);
Route::get('/admin/combined-statement', \App\Livewire\Admin\CombinedStatementComponent::class)->name('admin.combined-statement')->middleware(['feature:advanced_reports', 'feature:optical']);
Route::get('/admin/combined-statement/export/{format}', \App\Http\Controllers\CombinedStatementExportController::class)->whereIn('format', ['csv', 'pdf'])->name('admin.combined-statement.export')->middleware(['feature:advanced_reports', 'feature:optical']);
Route::get('/admin/income-statement/export/csv', [IncomeStatementExportController::class, 'exportCsv'])->name('admin.income-statement.export.csv')->middleware(['optical-only', 'feature:advanced_reports']);
Route::get('/admin/income-statement/export/pdf', [IncomeStatementExportController::class, 'exportPdf'])->name('admin.income-statement.export.pdf')->middleware(['optical-only', 'feature:advanced_reports']);
Route::get('/admin/income-statement/preview', [IncomeStatementExportController::class, 'preview'])->name('admin.income-statement.preview')->middleware(['optical-only', 'feature:advanced_reports']);
Route::get('/admin/diagnoses', \App\Livewire\Admin\DiagnosisComponent::class)->name('admin.diagnoses');
Route::get('/admin/inventory-alerts', InventoryAlertsComponent::class)->name('admin.inventory-alerts')->middleware('feature:inventory');
Route::get('/admin/stock-movements', StockMovementComponent::class)->name('admin.stock-movements')->middleware('feature:inventory');
Route::get('/admin/stock-transfers', \App\Livewire\Admin\StockTransferComponent::class)->name('admin.stock-transfers')->middleware('feature:inventory');
Route::get('/admin/daily-cash-summary', DailyCashSummaryComponent::class)->name('admin.daily-cash-summary');
Route::get('/admin/lens-outstanding-report', function () { // feature:spectacles_pro checked in middleware below
    $debts = \App\Models\LensOrder::with(['refraction.consultation.patient'])
        ->whereRaw('(frame_price + lens_price + glazing_fee + service_total - discount_amount) > paid_amount')
        ->where('status', '!=', 'Cancelled')
        ->get();

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.outstanding-report', [
        'debts' => $debts,
        'generated_at' => now(),
        'appSettings' => \App\Models\Setting::getSettings(),
    ])->setPaper('a4', 'landscape');

    return response()->streamDownload(fn () => print($pdf->output()), 'Lens_Outstanding_Report_' . date('Y-m-d') . '.pdf');
})->name('admin.lens-outstanding-report')->middleware('feature:spectacles_pro');
Route::get('/admin/login-history', LoginHistoryComponent::class)->name('admin.login-history')->middleware('feature:audit_trail');
Route::get('/admin/audit-trail', AuditTrailViewerComponent::class)->name('admin.audit-trail')->middleware('feature:audit_trail');
Route::get('/admin/license', LicenseComponent::class)->name('admin.license')->middleware(['auth', 'role:Super Admin']);
Route::get('/admin/sms-logs', SmsLogsComponent::class)->name('admin.sms-logs');
Route::get('/admin/expenses', \App\Livewire\Admin\ExpensesComponent::class)->name('admin.expenses')->middleware(['optical-only', 'feature:expense_tracking']);
Route::get('/admin/expenses/receipt/{expense}', function (\App\Models\Expense $expense) {
    abort_unless($expense->receipt_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($expense->receipt_path), 404);
    return \Illuminate\Support\Facades\Storage::disk('public')->download($expense->receipt_path, basename($expense->receipt_path), ['Content-Disposition' => 'inline']);
})->name('admin.expenses.receipt')->middleware(['auth']);
Route::get('admin/dashboard', AdminDashboardController::class)->name('admin.dashboard');
Route::get('admin/clinical-task-center', ClinicalTaskCenterComponent::class)->name('admin.clinical-task-center');
Route::get('admin/category', CategoryComponent::class)->name('admin.category');
Route::get('admin/product', ProductsComponent::class)->name('admin.product');
Route::get('admin/suppliers', \App\Livewire\Admin\SupplierComponent::class)->name('admin.suppliers')->middleware('feature:inventory');
Route::get('admin/quotations', \App\Livewire\Admin\QuotationComponent::class)->name('admin.quotations');
Route::get('admin/quotations/{id}/pdf', function (int $id) {
    $q = \App\Models\Quotation::with('items', 'creator')->findOrFail($id);
    $setting = \App\Models\Setting::getSettings();
    return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.quotation', compact('q', 'setting'))
        ->setPaper('a4', 'portrait')
        ->stream("Quotation-{$q->quotation_number}.pdf");
})->name('admin.quotations.pdf');
Route::get('admin/purchase-orders', \App\Livewire\Admin\PurchaseOrderComponent::class)->name('admin.purchase-orders');
Route::get('admin/purchase-orders/{id}/pdf', function (int $id) {
    $po = \App\Models\PurchaseOrder::with('items', 'supplier', 'creator')->findOrFail($id);
    $setting = \App\Models\Setting::getSettings();
    return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.purchase-order', compact('po', 'setting'))
        ->setPaper('a4', 'portrait')
        ->stream("PO-{$po->po_number}.pdf");
})->name('admin.purchase-orders.pdf');
Route::get('admin/patient-ledger', \App\Livewire\Admin\PatientLedgerComponent::class)->name('admin.patient-ledger');
// Route::get('users', UsersComponent::class)->name('admin.users');
Route::get('admin/settings', AdminSettingsComponent::class)->name('admin.settings');
Route::get('admin/branches', \App\Livewire\Admin\BranchManagementComponent::class)->name('admin.branches');

// Insurance module
Route::get('/admin/insurance/claims', \App\Livewire\Admin\InsuranceClaimsComponent::class)->name('admin.insurance.claims');
Route::get('/admin/insurance/insurers', \App\Livewire\Admin\InsurersComponent::class)->name('admin.insurance.insurers');
Route::get('/admin/insurance/receivables', \App\Livewire\Admin\InsurerReceivablesComponent::class)->name('admin.insurance.receivables');

// Patient Recall dashboard
Route::get('/admin/patient-recall', \App\Livewire\Admin\PatientRecallComponent::class)->name('admin.patient-recall')->middleware('feature:sms_campaigns');

});

// Communications: the clinic's messages, SMS credits, WhatsApp and broadcasts (Super Admin, as in Settings).
Route::middleware(['auth', 'role:Super Admin'])->prefix('admin/communications')->group(function () {
    Route::get('messages', \App\Livewire\Admin\SmsTemplatesComponent::class)->name('admin.messages');
    Route::get('sms', \App\Livewire\Admin\SmsSettingsComponent::class)->name('admin.sms-settings');
    Route::get('whatsapp', \App\Livewire\Admin\WhatsAppSettingsComponent::class)->name('admin.whatsapp-settings');
    Route::get('broadcast', \App\Livewire\Admin\BroadcastComponent::class)->name('admin.broadcast')->middleware('feature:sms_campaigns');
});

// Insurer remittances: Super Admin, or any role given the "record insurer payments" permission.
Route::get('admin/insurance/payments', \App\Livewire\Admin\InsurerPaymentsComponent::class)->middleware(['auth', 'role_or_permission:Super Admin|record insurer payments'])->name('admin.insurance.payments');

Route::get('admin/users', UserRoleManagerComponent::class)->middleware(['auth', 'role_or_permission:Super Admin|manage users'])->name('admin.users');
Route::get('admin/roles-permissions', RolePermissionManagerComponent::class)->middleware(['auth', 'role:Super Admin'])->name('admin.roles-permissions');
Route::get('admin/password-reset-approvals', fn() => redirect()->route('admin.approvals', ['type' => 'password_reset']))->middleware(['auth', 'role:Super Admin'])->name('admin.password-reset-approvals');

Route::middleware(['auth', 'role:Super Admin'])->group(function () {
    Route::get('admin/offline-health', OfflineHealthDashboardComponent::class)->name('admin.offline-health');

    // Legacy URLs redirect to the unified settings page with the correct tab
    Route::get('admin/backups',          fn() => redirect()->route('admin.settings', ['tab' => 'backup']))->name('admin.backups')->middleware('tenant.backup-access');
    Route::get('admin/report-delivery',  fn() => redirect()->route('admin.settings', ['tab' => 'report']))->name('admin.report-delivery');
    Route::get('admin/mail-settings',    fn() => redirect()->route('admin.settings'))->name('admin.mail-settings');
    Route::get('admin/backups/download/{filename}', function (string $filename) {
        $decoded = base64_decode($filename, strict: true);
        abort_if($decoded === false, 400);

        // Block traversal attempts; allow one subfolder level (e.g. AppName/2026-01-01.zip)
        abort_if(
            $decoded === '' ||
            str_contains($decoded, '..') ||
            str_starts_with($decoded, '/') ||
            str_starts_with($decoded, '\\'),
            403
        );

        $disk = Storage::disk('backups');
        abort_unless($disk->exists($decoded), 404);

        $extension = strtolower(pathinfo($decoded, PATHINFO_EXTENSION));
        $contentType = $extension === 'sql' ? 'application/sql' : 'application/zip';

        return response()->streamDownload(
            fn () => print($disk->get($decoded)),
            basename($decoded),
            ['Content-Type' => $contentType]
        );
    })->name('admin.backup.download')->middleware('feature:manual_backup');
});

Route::middleware(['auth', 'role_or_permission:Manager|Super Admin|manage billing|approve clearance revoke', 'feature:approvals'])->group(function () {
    Route::get('admin/approvals', AllApprovalsComponent::class)->name('admin.approvals');
    Route::get('admin/discount-approvals', fn() => redirect()->route('admin.approvals', ['type' => 'discount']))->name('admin.discount-approvals');
    Route::get('admin/refund-approvals', fn() => redirect()->route('admin.approvals', ['type' => 'refund']))->name('admin.refund-approvals');
    Route::get('admin/clearance-revoke-approvals', fn() => redirect()->route('admin.approvals', ['type' => 'revoke']))->name('admin.clearance-revoke-approvals');
    Route::post('discount-approval-notices/{discountRequest}/approve', [DiscountApprovalNoticeController::class, 'approve'])->name('discount-approval-notices.approve');
    Route::post('discount-approval-notices/{discountRequest}/reject', [DiscountApprovalNoticeController::class, 'reject'])->name('discount-approval-notices.reject');
});




Route::middleware(['auth', 'role:Doctor|Super Admin'])->group(function () {
 //doctor
Route::get('doctor/dashboard', DoctorDashboardController::class)->name('doctor.dashboard');

Route::get('doctor/patient-awaiting', PatientAwaitingComponent::class)->name('doctor.patient-awaiting');
Route::get('doctor/patientrecords-{clearance}', PatientRecordsComponent::class)->name('doctor.patient-records');
Route::get('doctor/allrecords', AllrecordsComponent::class)->name('doctor.all-records');
Route::get('/doctor/patient/{patient}/clearance/{clearance}/medical-record/pdf', 
    [PatientMedicalRecordController::class, 'generatePDF'])
    ->name('doctor.medical-record.pdf');
Route::get('/doctor/patient/{patient}/clearance/{clearance}/medical-record/preview', 
    [PatientMedicalRecordController::class, 'preview'])
    ->name('doctor.medical-record.preview');

Route::get('/doctor/consultation/{consultation}/pdf', 
    [PatientMedicalRecordController::class, 'generateConsultationPDF'])
    ->name('doctor.consultation.pdf');
Route::get('/doctor/consultation/{consultation}/visit-summary/print',
    [PatientMedicalRecordController::class, 'visitSummary'])
    ->name('doctor.visit-summary.print');
Route::get('/doctor/consultation/{consultation}/prescription/print',
    [PatientMedicalRecordController::class, 'printPrescription'])
    ->name('doctor.prescription.print');
Route::get('/doctor/patient/{patient}/timeline',
    \App\Livewire\Doctor\PatientTimelineComponent::class)
    ->name('doctor.patient-timeline');
Route::get('/doctor/visit-summaries/download',
    [PatientMedicalRecordController::class, 'downloadVisitSummaries'])
    ->name('doctor.visit-summaries.download');

Route::get('doctor/referrals', ReferralComponent::class)->name('doctor.referrals')->middleware('feature:referrals');
Route::get('/doctor/referrals/{referral}/print', [ReferralController::class, 'printLetter'])->name('doctor.referral.pdf')->middleware('feature:referrals');

Route::get('/refraction/print/{consultation}', function($consultationID){
    abort_if(!auth()->user()->hasAnyRole(['Doctor', 'Super Admin', 'Manager']), 403);

    $consultation = \App\Models\Consultations::with('patient')->findOrFail($consultationID);
    $refraction   = \App\Models\Refractions::where('consultation_id', $consultationID)->first();
    $patient      = $consultation->patient;

    return view('livewire.doctor.refraction-print', compact('consultation', 'refraction', 'patient'));
})->name('refraction.print');
});
