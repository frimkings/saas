@php([$label, $tone] = match ($batch->status) {
    'analyzed' => ['Checked, not imported', 'neutral'],
    'queued' => ['Waiting to start', 'warning'],
    'importing' => ['Importing '.$batch->progress_percent.'%', 'warning'],
    'completed' => ['Imported', 'good'],
    'failed' => ['Failed', 'danger'],
    'rolled_back' => ['Undone', 'neutral'],
    default => [ucfirst($batch->status), 'neutral'],
})
<span class="ui-badge ui-badge--{{ $tone }}">{{ $label }}</span>
