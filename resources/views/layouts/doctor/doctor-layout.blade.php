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
        @include('layouts.doctor.aside-doctor')

        <!-- Content Wrapper. Contains page content -->
        <div class="content-wrapper">
            @include('components.license-notice')
            <!-- Content Header (Page header) -->
            {{$slot}}
            <!-- /.content -->
            @yield('content')

            
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

    <audio id="clearance-notif-ping" preload="auto">
        <source src="{{ asset('sounds/notification.mp3') }}" type="audio/mpeg">
        <source src="https://assets.mixkit.co/active_storage/sfx/2358/2358-preview.mp3" type="audio/mpeg">
    </audio>

    @auth
        <script>
            (function () {
                var latestUrl = '{{ route("doctor.clearance-notices.latest") }}';
                var storageKey = 'doctorLatestClearanceNoticeId';
                var initialized = false;

                function playNotificationSound() {
                    var audio = document.getElementById('clearance-notif-ping');
                    if (audio) audio.play().catch(function () {});
                }

                function showNewClearanceToast(data) {
                    var patient = data.patient_name || 'A patient';
                    var folder = data.patient_number ? ' (' + data.patient_number + ')' : '';
                    var count = Number(data.pending_count || 0);
                    var suffix = count > 1 ? ' ' + count + ' patients are now awaiting doctor review.' : '';

                    playNotificationSound();

                    if (window.toastr) {
                        toastr.info(patient + folder + ' has been added under clearance.' + suffix, 'New Patient Clearance');
                    }
                }

                function rememberLatest(latestId) {
                    if (latestId) {
                        sessionStorage.setItem(storageKey, latestId);
                    }
                }

                function checkClearanceNotices() {
                    fetch(latestUrl, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Unable to check clearance notices.');
                            }

                            return response.json();
                        })
                        .then(function (data) {
                            var latestId = Number(data.latest_id || 0);
                            var rememberedId = Number(sessionStorage.getItem(storageKey) || 0);

                            if (!latestId) {
                                initialized = true;
                                return;
                            }

                            if (!initialized && !rememberedId) {
                                rememberLatest(latestId);
                                initialized = true;
                                return;
                            }

                            if (latestId > rememberedId) {
                                showNewClearanceToast(data);
                                rememberLatest(latestId);
                            }

                            initialized = true;
                        })
                        .catch(function () {});
                }

                checkClearanceNotices();
                setInterval(checkClearanceNotices, 15000);
            })();
        </script>
    @endauth
<x-ui.flash />
@include('layouts.partials.confirm-dialog')
</body>
</html>
