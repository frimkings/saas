<div class="clinic-ui ui-preview-shell">
    <header class="ui-preview-top">
        <div class="ui-preview-brand"><span class="ui-preview-mark" aria-hidden="true"></span><div>Eye Clinic<p class="ui-muted">Clinic management</p></div></div>
        <div class="ui-actions"><span class="ui-muted">Administrator</span><span class="ui-preview-avatar">AD</span></div>
    </header>
    <div class="ui-preview-layout">
        <nav class="ui-preview-nav" aria-label="Design preview navigation">
            <p class="ui-preview-group">WORKSPACE</p>
            <a href="#clinic-overview" aria-current="page">Overview</a>
            <a href="{{ route('admin.expenses') }}">Expenses</a>
            <span>Patients</span><span>Appointments</span><span>Sales records</span><span>Inventory</span><span>Reports</span>
        </nav>
        <main class="ui-preview-main">{{ $slot }}</main>
    </div>
</div>
