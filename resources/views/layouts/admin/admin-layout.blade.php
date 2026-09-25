<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.scripts')
</head>

<body class="hold-transition sidebar-mini">
    <div class="wrapper">

        <!-- Navbar -->
        <x-navbar />



        <!-- Main Sidebar Container -->
        @include('layouts.admin.aside-admin')

        <!-- Content Wrapper. Contains page content -->
        <div class="content-wrapper">
            @include('components.license-notice')
            <!-- Content Header (Page header) -->
            {{$slot}}
            <!-- /.content -->
            {{-- @yield('content') --}}


        </div>
        <!-- /.content-wrapper -->

        <!-- Control Sidebar -->
        <aside class="control-sidebar control-sidebar-dark">
            <!-- Control sidebar content goes here -->
            <div class="p-3">
                <h5>Title</h5>
                <p>Sidebar content</p>
            </div>
        </aside>
        <!-- /.control-sidebar -->

        <!-- Main Footer -->
       <x-footer />

    </div>
</body>
</html>
