{{-- Legacy Imports has two tabs: our own older system's SQL dump (creates a clinic) and other vendors' exports (fills an onboarded clinic). --}}
<nav class="ui-tabs" aria-label="Import type" data-tour="xi-tabs" style="margin-bottom:14px">
    <a href="{{ route('platform.imports') }}" class="{{ $tab === 'sql' ? 'active' : '' }}" @if($tab === 'sql') aria-current="page" @endif>Our older system (SQL dump)</a>
    <a href="{{ route('platform.imports.other') }}" class="{{ $tab === 'other' ? 'active' : '' }}" @if($tab === 'other') aria-current="page" @endif>From other software</a>
</nav>
