@php
    $latestRx = $historyRefractions->first();
    $activeRisks = collect($eyeDiseaseRiskFlags)->whereIn('level', ['warning', 'urgent']);
@endphp
<div class="history-health-grid">
    <div class="history-health-card history-health-card--slate">
        <h3>Systemic Health <i class="fas fa-heartbeat" aria-hidden="true"></i></h3>
        <strong>{{ filled($latestHistoryVisit?->others) ? 'Recorded medical history' : 'Not recorded' }}</strong>
        <p>{{ filled($latestHistoryVisit?->others) ? Str::limit($latestHistoryVisit->others, 130) : 'No structured systemic health information available.' }}</p>
    </div>
    <div class="history-health-card history-health-card--amber">
        <h3>Ocular Risk <i class="fas fa-exclamation-triangle" aria-hidden="true"></i></h3>
        <strong>{{ $activeRisks->isNotEmpty() ? $activeRisks->pluck('name')->implode(', ') : 'No recorded risk flags' }}</strong>
        <p>Based on recorded visits. Review supporting findings below.</p>
    </div>
    <div class="history-health-card history-health-card--blue">
        <h3>Latest Refraction <i class="fas fa-glasses" aria-hidden="true"></i></h3>
        <strong>{{ $latestRx?->lensType ?: 'No lens type recorded' }}</strong>
        <p>OD: {{ $latestRx?->subjectiveRx('od') ?: '—' }}<br>OS: {{ $latestRx?->subjectiveRx('os') ?: '—' }}</p>
        @if($latestRx)<small>{{ $latestRx->created_at->format('d M Y') }} · {{ $latestRx->lensOrder ? 'Spectacle order recorded' : 'Clinical prescription' }}</small>@endif
    </div>
    <div class="history-health-card history-health-card--rose">
        <h3>Allergies <i class="fas fa-ban" aria-hidden="true"></i></h3>
        <strong>Not recorded</strong>
        <p>No structured allergy record available. Check the clinical notes.</p>
    </div>
</div>
