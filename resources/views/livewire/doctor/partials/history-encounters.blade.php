<div class="history-surface-heading"><h3><i class="fas fa-history text-primary"></i> Past Encounters & Consultation Timeline</h3><span>{{ $patientRecords->total() }} visits found</span></div>
<div class="history-encounters">
@forelse($patientRecords as $record)
    <article class="history-encounter" wire:key="history-encounter-{{ $record->id }}">
        <header>
            <div class="history-encounter-identity"><input type="checkbox" class="modern-checkbox" wire:model.live="selectedVisitSummaries" value="{{ $record->id }}" aria-label="Select visit {{ $record->created_at->format('d M Y') }} for download"><span class="history-visit-number">#{{ $record->id }}</span><div><h4>{{ $record->chiefComplaint ?: 'Consultation' }}</h4><p>{{ $record->created_at->format('d F Y · h:i A') }} · Doctor: {{ $record->user?->name ?: 'Not recorded' }}</p></div></div>
            <a href="{{ route('doctor.visit-summary.print', $record) }}" target="_blank" rel="noopener" class="px-btn px-btn--primary"><i class="fas fa-eye"></i> View Full Detailed Record</a>
        </header>
        <div class="history-encounter-grid">
            <div><h5>Diagnoses</h5>@forelse($record->diagnoses as $diagnosis)<span class="diag-chip">{{ $diagnosis->name }}</span>@empty<p>No diagnosis recorded.</p>@endforelse</div>
            <div><h5>Clinical Findings & IOP</h5><p><span class="history-eye-od">OD</span> {{ $record->IOPOD ?? '—' }} · <span class="history-eye-os">OS</span> {{ $record->IOPOS ?? '—' }} mmHg</p><p>VA: OD {{ $record->vaOD6m ?: '—' }} · OS {{ $record->vaOS6m ?: '—' }}</p><p>{{ Str::limit($record->notes, 150) }}</p></div>
            <div><h5>Dispensing & Rx Details</h5>@if($record->refraction)<p>{{ $record->refraction->lensType ?: 'No lens type recorded' }}</p><p>{{ $record->refraction->lensOrder ? 'Order '.$record->refraction->lensOrder->order_id : 'No spectacle order recorded' }}</p>@else<p>No refraction recorded.</p>@endif</div>
        </div>
        <footer><button type="button" class="px-btn px-btn--ghost" wire:click="editConsultation({{ $record->id }})"><i class="fas fa-edit"></i> Open Consultation</button><button type="button" class="px-btn px-btn--ghost" wire:click="loadRefractionData({{ $record->id }})"><i class="fas fa-glasses"></i> Refraction</button><button type="button" class="px-btn px-btn--ghost" wire:click="printRefraction({{ $record->id }})"><i class="fas fa-print"></i> Print Refraction</button><a href="{{ route('doctor.prescription.print', $record) }}" target="_blank" rel="noopener" class="px-btn px-btn--ghost"><i class="fas fa-prescription"></i> Drug Prescription</a></footer>
    </article>
@empty
    <div class="history-surface table-empty">No consultation records found.</div>
@endforelse
</div>
<div class="pagination-wrap">{{ $patientRecords->links() }}</div>
