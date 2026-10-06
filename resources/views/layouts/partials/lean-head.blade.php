{{-- Head for standalone pages with their own styling (platform console, clinic/workspace choosers):
     Tailwind build, icons, Livewire and the signed-in session scripts. No Bootstrap, AdminLTE or jQuery. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
<link rel="stylesheet" href="{{ asset('backend/plugins/fontawesome-free/css/all.min.css') }}">
@livewireStyles
@include('layouts.partials.session-scripts')
