<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('layouts.scripts')
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <x-navbar />

        @include('layouts.secretary.aside-secretary')

        <div class="content-wrapper">
            @include('components.license-notice')
            {{$slot}}
        </div>

        <aside class="control-sidebar control-sidebar-dark">
            <div class="p-3">
                <h5>Title</h5>
                <p>Sidebar content</p>
            </div>
        </aside>

        <x-footer />
    </div>

    @livewireCalendarScripts
</body>
</html>
