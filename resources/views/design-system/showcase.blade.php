<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clinic design system</title>@vite(['resources/css/app.css'])</head>
<body>
<x-ui.preview-shell>
<section id="clinic-overview">
<header class="ui-heading"><div><h1>Clinic overview</h1><p class="ui-muted">Monday, 14 September 2026</p></div><x-ui.badge>Today's schedule</x-ui.badge></header>
<div class="ui-stats">
<x-ui.stat label="Appointments" value="24"><p class="ui-muted">8 remaining today</p></x-ui.stat>
<x-ui.stat label="Waiting patients" value="06"><p class="ui-muted">2 doctors on duty</p></x-ui.stat>
<x-ui.stat label="Today's collections" value="₱18,450"><p class="ui-muted">16 paid transactions</p></x-ui.stat>
</div>
<x-ui.panel>
<div class="ui-panel-heading"><h2>Upcoming appointments</h2><span class="ui-muted">Afternoon</span></div>
<div class="ui-appointment"><span class="ui-muted">1:00 PM</span><div>Alex Santos<p class="ui-muted">Eye examination · Dr. Reyes</p></div><x-ui.badge class="ui-badge-waiting">Waiting</x-ui.badge></div>
<div class="ui-appointment"><span class="ui-muted">1:30 PM</span><div>Jamie Cruz<p class="ui-muted">Follow-up · Dr. Garcia</p></div><x-ui.badge>Checked in</x-ui.badge></div>
<div class="ui-appointment"><span class="ui-muted">2:00 PM</span><div>Sam Rivera<p class="ui-muted">Eye examination · Dr. Reyes</p></div><x-ui.badge>Confirmed</x-ui.badge></div>
</x-ui.panel>
<footer class="ui-preview-footer ui-muted"><span>Design prototype · Sample data</span><span>Eye Clinic / Admin workspace</span></footer>
</section>
<details class="ui-preview-components" open><summary>Component showcase</summary>
<div class="ui-stack">
<x-ui.page-header title="Products" description="What you sell, with prices and stock.">
    <x-slot:badge><x-ui.badge>12 low</x-ui.badge></x-slot:badge>
    <x-ui.menu label="More"><button type="button" class="ui-menu-item" role="menuitem"><i class="fas fa-file-csv" aria-hidden="true"></i>Export CSV</button><button type="button" class="ui-menu-item" role="menuitem"><i class="fas fa-file-import" aria-hidden="true"></i>Import CSV</button></x-ui.menu>
    <x-ui.button variant="primary"><i class="fas fa-plus" aria-hidden="true"></i>Add product</x-ui.button>
</x-ui.page-header>
<div class="ui-tiles">
    <x-ui.stat-tile label="Collected" value="GH₵ 545.00" hint="All methods" icon="fa-money-bill-wave" tone="accent" />
    <x-ui.stat-tile label="Balanced" value="GH₵ 0.00" hint="Count matches" tone="good" />
    <x-ui.stat-tile label="Still owed" value="GH₵ 80.00" hint="On today's sales" tone="warning" href="#" />
    <x-ui.stat-tile label="Short" value="−GH₵ 5.00" hint="Less cash than expected" tone="danger" />
</div>
<x-ui.section title="Payments taken" description="A section with a step number, actions and an empty state." step="3">
    <x-slot:actions><x-ui.button class="ui-button-sm">Export</x-ui.button></x-slot:actions>
    <p class="ui-section-empty">No payments on this day.</p>
</x-ui.section>
<x-ui.section title="Field with a hint" icon="fa-pen">
    <div class="ui-form ui-grid"><x-ui.field label="Amount" name="example.hinted" type="number" hint="In cedis, without the currency sign" required /><x-ui.field label="Reason" name="example.reason" type="textarea" rows="2" /></div>
</x-ui.section>
<div class="ui-stats"><x-ui.stat label="Period total" value="PHP 42,800.00" /><x-ui.stat label="Today" value="PHP 2,500.00" /><x-ui.stat label="Records" value="24" /></div>
<x-ui.panel><div class="ui-panel-heading"><h2>Actions and status</h2></div><div class="ui-form"><div class="ui-actions"><x-ui.button variant="primary">Record expense</x-ui.button><x-ui.button>Export CSV</x-ui.button><x-ui.button variant="danger">Delete</x-ui.button><x-ui.button disabled>Saving…</x-ui.button><x-ui.badge>Active</x-ui.badge></div></div></x-ui.panel>
<x-ui.panel><div class="ui-panel-heading"><h2>Form fields</h2></div><div class="ui-form ui-grid"><x-ui.field label="Description (required)" name="example.description" placeholder="Monthly clinic rent" /><x-ui.field label="Category" name="example.category" :options="['supplies' => 'Supplies', 'rent' => 'Rent']" /><x-ui.field label="Date" name="example.date" type="date" /><x-ui.field label="Amount (PHP)" name="example.amount" type="number" placeholder="0.00" /></div></x-ui.panel>
<x-ui.panel><div class="ui-panel-heading"><h2>Expense table</h2></div><div class="ui-table-wrap"><table class="ui-table"><thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Category</th><th scope="col" class="ui-number">Amount</th></tr></thead><tbody><tr><td>14 Sep 2026</td><td>Lens cleaning supplies</td><td><x-ui.badge>Supplies</x-ui.badge></td><td class="ui-number">PHP 2,500.00</td></tr></tbody></table></div></x-ui.panel>
</div>
</details>
</x-ui.preview-shell>
</body>
</html>
