<section class="history-surface">
    <div class="history-surface-heading"><h3><i class="fas fa-chart-bar text-teal-700"></i> Refraction Progression History</h3><span>Last 3 years · Latest 30 records · OD / OS</span></div>
    <div class="modern-table-wrap"><table class="modern-table history-rx-table">
        <thead><tr><th>Date</th><th>Eye</th><th>SPH</th><th>CYL</th><th>Axis</th><th>BCVA</th><th>Lens Type / Order</th><th>Examined By</th></tr></thead>
        <tbody>
        @forelse($historyRefractions as $rx)
            @foreach(['od' => 'OD (Right)', 'os' => 'OS (Left)'] as $eye => $label)
                <tr>
                    @if($loop->first)<td rowspan="2"><strong>{{ $rx->created_at->format('d M Y') }}</strong></td>@endif
                    <td class="history-eye-{{ $eye }}">{{ $label }}</td>
                    <td>{{ $rx->{'subjective_'.$eye.'_sphere'} !== null ? \App\Models\Refractions::formatPower($rx->{'subjective_'.$eye.'_sphere'}) : '—' }}</td>
                    <td>{{ $rx->{'subjective_'.$eye.'_cylinder'} !== null ? \App\Models\Refractions::formatPower($rx->{'subjective_'.$eye.'_cylinder'}) : '—' }}</td>
                    <td>{{ $rx->{'subjective_'.$eye.'_axis'} !== null ? $rx->{'subjective_'.$eye.'_axis'}.'°' : '—' }}</td>
                    <td>{{ $rx->{'subjective_'.$eye.'_bcva'} ?: ($rx->{'refraction'.strtoupper($eye).'_distance_va'} ?: '—') }}</td>
                    @if($loop->first)<td rowspan="2"><span class="diag-chip">{{ $rx->lensType ?: 'Not recorded' }}</span><small class="block text-slate-500">{{ $rx->lensOrder ? 'Order '.$rx->lensOrder->order_id : 'No spectacle order' }}</small></td><td rowspan="2">{{ $rx->user?->name ?: 'Not recorded' }}</td>@endif
                </tr>
            @endforeach
        @empty
            <tr><td colspan="8" class="table-empty">No refraction records in the last three years.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
