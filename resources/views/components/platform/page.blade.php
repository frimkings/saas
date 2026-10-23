@props(['active', 'title', 'subtitle' => null, 'tour' => null])
{{-- Platform page frame: the shared ui-* components in their dark theme (resources/css/ui-dark.css), the sidebar and the page header. --}}
<div class="pp-shell clinic-ui ui-dark">
<style>
body{margin:0}
/* Older names some page styles still use for the dark theme's colours. */
.pp-shell{--bg:var(--clinic-surface);--panel:var(--clinic-panel);--line:var(--clinic-line);--muted:var(--clinic-muted);--label:var(--clinic-label);--blue:var(--clinic-accent);--green:#34d399;--amber:#fbbf24;--red:#fb7185;min-height:100vh;display:flex}
.pp-main{flex:1;min-width:0;padding:24px 28px;max-width:1300px;box-sizing:border-box}
.pp-main textarea.ui-input{min-height:70px}
.pass{color:var(--green)}.warn{color:var(--amber)}.fail{color:var(--red)}
@media(max-width:900px){.pp-shell{display:block}.pp-main{padding:18px 16px}}
</style>
<x-platform.sidebar :active="$active" />
<main class="pp-main">
    <x-ui.page-header :title="$title" :description="$subtitle">
        {{-- A guided tour of the page (App\Support\Guides\TourCatalog: platform.<active>), started by itself the first time. --}}
        <x-guide-tour :page="$tour ?? 'platform.'.$active" />{{ $actions ?? '' }}
    </x-ui.page-header>
    {{ $slot }}
</main>
</div>
