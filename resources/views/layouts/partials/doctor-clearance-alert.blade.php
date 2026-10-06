{{-- Doctors: a sound and a toast when a patient is cleared for them (from the page poll). --}}
    <audio id="clearance-notif-ping" preload="auto">
        <source src="{{ asset('sounds/notification.mp3') }}" type="audio/mpeg">
        <source src="https://assets.mixkit.co/active_storage/sfx/2358/2358-preview.mp3" type="audio/mpeg">
    </audio>

    @auth
        <script>
            (function () {
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

                    var message = patient + folder + ' has been added under clearance.' + suffix;
                    window.dispatchEvent(new CustomEvent('notify', { detail: { type: 'info', message: message, link: @js(route('doctor.patient-awaiting')), linkLabel: 'Open the queue' } }));
                }

                function rememberLatest(latestId) {
                    if (latestId) {
                        sessionStorage.setItem(storageKey, latestId);
                    }
                }

                if (!window.appPulse) return;

                window.appPulse.on('clearance', function (data) {
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
                });
            })();
        </script>
    @endauth
