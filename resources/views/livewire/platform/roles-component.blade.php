<x-platform.page active="roles" title="Roles" subtitle="What each shared role can do in every clinic, who holds them, and the per-person switches clinics have made. Read-only: roles change in code (app/Support/Access/RoleMap.php), tested and deployed like any other change.">
    <style>
        .rl-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}@media(max-width:900px){.rl-cards{grid-template-columns:1fr}}
        .rl-card h3{margin:0 0 4px;font-size:14px}.rl-card p{margin:0;color:var(--muted);font-size:12px}.rl-card b{float:right;color:var(--blue)}
        .rl-num{text-align:right!important;white-space:nowrap}.rl-group td{background:#0b1526;color:var(--muted);font-size:11px;text-transform:uppercase;font-weight:700}
        .rl-yes{color:var(--green)}.rl-no{color:#3b4b63}.rl-sub{display:block;font-size:11px;color:var(--muted)}
        .rl-bar{display:flex;justify-content:flex-end;margin-bottom:8px}.rl-bar .ui-field{width:auto;min-width:200px}
    </style>

    <div class="rl-cards" data-tour="rl-cards">
        @foreach($labels as $role => $label)
            <div class="ui-card rl-card" style="padding:14px">
                <h3>{{ $label }} @if($label !== $role)<span class="rl-sub" style="display:inline">({{ $role }})</span>@endif <b title="Active people holding it, all clinics">{{ $totals[$role] ?? 0 }}</b></h3>
                <p>{{ \App\Support\Access\RoleGuide::about($role) }}</p>
            </div>
        @endforeach
    </div>

    <div class="ui-card" data-tour="rl-matrix" style="margin-bottom:16px">
        <div class="ui-card-head"><div><h2>Permissions by role</h2><p>The same table clinics see under Staff → What each role can do. The optical rows at the bottom are the optical screens' own permissions (OpticalAccess).</p></div>
            <label class="ui-field"><span>Show</span>
                <select class="ui-input" wire:model.live="role"><option value="">Every role</option>@foreach($labels as $role => $label)<option value="{{ $role }}">{{ $label }}</option>@endforeach</select>
            </label>
        </div>
        <div class="ui-table-wrap ui-table-wrap--framed">
            <table class="ui-table">
                <thead><tr><th>Permission</th>@foreach($shown as $label)<th class="rl-num">{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($matrix as $group => $rows)
                    <tr class="rl-group"><td colspan="{{ count($shown) + 1 }}">{{ $group }}</td></tr>
                    @foreach($rows as $key => $row)
                        <tr><td>{{ $row['label'] }}<span class="rl-sub">{{ $key }}</span></td>
                            @foreach(array_keys($shown) as $role)<td class="rl-num">@if($row['roles'][$role])<span class="rl-yes">✓</span>@else<span class="rl-no">–</span>@endif</td>@endforeach
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="ui-card" data-tour="rl-clinics">
        <div class="ui-card-head"><div><h2>By clinic</h2><p>Active people in each role (a person with two roles counts in both), and the switches made on their Access pages.</p></div></div>
        <div class="ui-table-wrap ui-table-wrap--framed">
            <table class="ui-table">
                <thead><tr><th>Clinic</th>@foreach($labels as $label)<th class="rl-num">{{ $label }}</th>@endforeach<th class="rl-num">Switches</th></tr></thead>
                <tbody>
                @forelse($clinics as $id => $name)
                    <tr wire:key="rl-clinic-{{ $id }}"><td>{{ $name }}</td>
                        @foreach(array_keys($labels) as $role)<td class="rl-num">{{ $holders[$id][$role] ?? '–' }}</td>@endforeach
                        <td class="rl-num">@if(isset($switches[$id]))<span title="{{ implode(', ', $switches[$id]['top']) }}">{{ $switches[$id]['on'] }} on · {{ $switches[$id]['off'] }} off</span><span class="rl-sub">{{ implode(', ', $switches[$id]['top']) }}</span>@else–@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($labels) + 2 }}" class="ui-muted">No clinic staff yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-platform.page>
