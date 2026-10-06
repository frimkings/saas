<section class="history-surface">
    <div class="history-surface-heading"><div><h3><i class="fas fa-images text-teal-700"></i> Diagnostic Scans & Attachments</h3><p>Latest 12 uploaded files · Open a file to inspect the original.</p></div><button type="button" class="px-btn px-btn--primary" wire:click="switchTab('bills')"><i class="fas fa-upload"></i> Upload / Manage</button></div>
    <div class="history-scan-grid">
        @forelse($historyDocuments as $document)
            <article class="history-scan-card">
                <a href="{{ $document->url }}" target="_blank" rel="noopener" class="history-scan-preview" aria-label="Open {{ $document->title }}">
                    @if(str_starts_with($document->mime_type ?? '', 'image/'))
                        <img src="{{ $document->url }}" alt="{{ $document->title }}" loading="lazy">
                    @else
                        <span><i class="fas fa-file-medical"></i><small>{{ strtoupper(pathinfo($document->original_name, PATHINFO_EXTENSION)) ?: 'DOCUMENT' }}</small></span>
                    @endif
                </a>
                <div class="history-scan-caption"><span class="diag-chip">{{ $document->document_type }}</span><h4>{{ $document->title ?: $document->original_name }}</h4><p>{{ $document->created_at->format('d M Y') }} · {{ $document->uploadedBy?->name ?: 'Unknown author' }}</p><a href="{{ $document->url }}" target="_blank" rel="noopener">View original <i class="fas fa-external-link-alt"></i></a></div>
            </article>
        @empty
            <div class="table-empty">No diagnostic scans or documents uploaded yet.</div>
        @endforelse
    </div>
</section>
