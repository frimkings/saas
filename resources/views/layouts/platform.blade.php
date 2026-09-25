<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.scripts')
    <title>Platform Administration</title>
</head>
<body class="bg-light">
    {{ $slot }}
</body>
</html>
