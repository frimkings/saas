<x-platform.page active="readiness" title="Production Deployment Readiness" subtitle="Environment, security, runtime, storage, recovery and communications release gate.">
    <x-slot:actions><button type="button" class="ui-button ui-button-primary" wire:click="runAudit" wire:loading.attr="disabled"><span wire:loading.remove wire:target="runAudit">Run Audit Now</span><span wire:loading wire:target="runAudit">Checking...</span></button></x-slot:actions>
    <style>.dr-check{display:grid;grid-template-columns:130px 1fr 90px;gap:12px;padding:11px 4px;border-bottom:1px solid var(--clinic-line)}.dr-check:last-child{border-bottom:0}@media(max-width:750px){.dr-check{grid-template-columns:1fr}}</style>
    <x-ui.flash :map="['readiness_message' => 'success']" />

    @if($latest)
        <div class="ui-tiles" style="margin-bottom:16px">
            <x-ui.stat-tile label="Overall status" :value="strtoupper($latest->status)" :tone="$latest->ready ? 'good' : 'danger'" />
            <x-ui.stat-tile label="Passed" :value="$latest->passed_checks" tone="good" />
            <x-ui.stat-tile label="Warnings" :value="$latest->warning_checks" :tone="$latest->warning_checks ? 'warning' : 'neutral'" />
            <x-ui.stat-tile label="Critical failures" :value="$latest->failed_checks" :tone="$latest->failed_checks ? 'danger' : 'neutral'" />
        </div>
        <section class="ui-card">
            <div class="ui-card-head"><div><h2>Latest Audit</h2><p>{{ $latest->completed_at->format('d M Y H:i:s') }} UTC · {{ $latest->host }} · {{ $latest->operator?->name ?? 'CLI/System' }}</p></div></div>
            @foreach($latest->report['checks'] as $check)
                <div class="dr-check">
                    <b>{{ $check['category'] }}</b>
                    <div><strong>{{ $check['label'] }}</strong><p class="ui-muted">{{ $check['detail'] }}</p>@if(!$check['passed'])<p class="warn">Remediation: {{ $check['remediation'] }}</p>@endif</div>
                    <span><span class="ui-badge {{ $check['passed'] ? 'ui-badge--good' : ($check['severity'] === 'critical' ? 'ui-badge--danger' : 'ui-badge--warning') }}">{{ $check['passed'] ? 'PASS' : strtoupper($check['severity']) }}</span></span>
                </div>
            @endforeach
        </section>
    @else
        <div class="ui-alert ui-alert--warning">No readiness audit has been recorded. Run the audit before pilot deployment.</div>
    @endif

    <section class="ui-card">
        <div class="ui-card-head"><div><h2>Recent Runs</h2></div></div>
        <div class="ui-table-wrap ui-table-wrap--framed">
            <table class="ui-table">
                <thead><tr><th>Time</th><th>Environment</th><th>Host</th><th>Operator</th><th>Passed</th><th>Warnings</th><th>Failed</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($history as $run)
                    <tr><td>{{ $run->completed_at->format('d M Y H:i') }}</td><td>{{ $run->environment }}</td><td>{{ $run->host }}</td><td>{{ $run->operator?->name ?? 'CLI/System' }}</td><td>{{ $run->passed_checks }}</td><td>{{ $run->warning_checks }}</td><td>{{ $run->failed_checks }}</td><td><span class="ui-badge {{ $run->ready ? 'ui-badge--good' : 'ui-badge--danger' }}">{{ strtoupper($run->status) }}</span></td></tr>
                @empty
                    <tr><td colspan="8" class="ui-empty">No audit history.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="ui-card">
        <div class="ui-card-head"><div><h2>CLI release gate</h2><p>Exit code 0 means the server passed all critical checks and has no warnings. Use <code>--json</code> for deployment automation.</p></div></div>
        <pre style="margin:0">php artisan platform:deployment-readiness --strict</pre>
    </section>
</x-platform.page>
