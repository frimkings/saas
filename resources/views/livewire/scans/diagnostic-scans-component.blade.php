<div class="clinic-ui ui-page space-y-5">
    @include('livewire.optical.partials.order-ui')
    <style>
        .ds-tabs{display:flex;gap:4px;border-bottom:1px solid var(--clinic-line);overflow-x:auto;overflow-y:hidden}
        .ds-tab{border:0;background:none;padding:10px 14px;font-size:13px;font-weight:700;color:var(--clinic-muted);border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer;white-space:nowrap}
        .ds-tab:hover{color:var(--clinic-ink)}
        .ds-tab.active{color:var(--clinic-accent);border-bottom-color:var(--clinic-accent)}
        .ds-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .ds-tile{background:#fff;border:1px solid var(--clinic-line);border-radius:12px;padding:12px 14px}
        .ds-tile span{display:block;font-size:11px;font-weight:700;color:var(--clinic-muted);text-transform:uppercase;letter-spacing:.3px}
        .ds-tile b{display:block;font-size:22px;margin-top:4px;font-variant-numeric:tabular-nums}
        .ds-field{display:flex;flex-direction:column;gap:4px}.ds-field>span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.ds-field .ui-input{padding:8px 10px}
        .ds-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ds-2 .full{grid-column:1/-1}
        .ds-err{color:#b91c1c;font-size:11px}.ds-hint{color:var(--clinic-muted);font-size:11px}
        @media(max-width:900px){.ds-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.ds-2{grid-template-columns:minmax(0,1fr)}}
    </style>

    <div class="ui-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-slate-900">Diagnostic Scans</h1>
            <x-guide-tour page="clinic.scans" :context="['manager' => \App\Livewire\Scans\DiagnosticScansComponent::canManage()]" />
        </div>
        <p class="ui-muted text-sm">Register and bill patients' scans, follow them to the report, and keep the scans you offer and the clinics that refer patients to you.</p>
    </div>

    <nav class="ds-tabs" role="tablist" aria-label="Diagnostic scans">
        @foreach($tabs as $key => $label)
            <button type="button" role="tab" data-tour="scan-tab-{{ $key }}" wire:click="setTab('{{ $key }}')" class="ds-tab {{ $tab === $key ? 'active' : '' }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if($tab === 'partners')
        <livewire:scans.scan-partners-component wire:key="ds-tab-partners" />
    @elseif($tab === 'commissions')
        <livewire:scans.scan-commissions-component wire:key="ds-tab-commissions" />
    @elseif($tab === 'catalogue')
        <livewire:scans.scan-catalogue-component wire:key="ds-tab-catalogue" />
    @else
        <livewire:scans.scan-desk-component wire:key="ds-tab-desk" />
    @endif
</div>
