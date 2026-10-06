<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.lean-head')
    <title>Platform Administration</title>
</head>
{{-- Platform console: its pages carry their own dark styling (pa-*, pp-*); no Bootstrap. --}}
<body class="platform-ui">
@include('layouts.partials.toasts')
    {{ $slot }}
<x-ui.flash />
@include('layouts.partials.confirm-dialog')
@livewireScripts
@include('layouts.partials.ui-toggles')
</body>
</html>
