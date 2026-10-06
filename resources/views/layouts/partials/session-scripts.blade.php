{{-- Signed-in page behaviour shared by every layout: idle logout after 30 minutes, the one
     page poll (appPulse) and, for Managers, discount approval prompts. --}}
@auth
<script>
(function () {
    var IDLE_LIMIT = 30 * 60 * 1000; // 30 minutes
    var WARN_BEFORE = 60 * 1000;     // warn 1 minute before
    var ACTIVITY_KEY = 'eyeclinic:lastActivityAt';
    var LOGOUT_KEY = 'eyeclinic:forceLogoutAt';
    var warnTimer;
    var logoutTimer;
    var warningOpen = false;
    var warningTitle = 'Session Expiring';

    function now() {
        return Date.now();
    }

    function lastActivityAt() {
        return parseInt(localStorage.getItem(ACTIVITY_KEY) || '0', 10) || now();
    }

    function submitLogout() {
        localStorage.setItem(LOGOUT_KEY, String(now()));

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route("logout") }}';
        form.style.display = 'none';

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = '{{ csrf_token() }}';

        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
    }

    function resetTimers() {
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);

        if (warningOpen && window.Swal && Swal.isVisible() && Swal.getTitle()?.textContent === warningTitle) {
            Swal.close();
        } else if (warningOpen && !window.Swal) {
            window.dispatchEvent(new CustomEvent('app-confirm-close'));
        }
        warningOpen = false;

        var remaining = Math.max(0, IDLE_LIMIT - (now() - lastActivityAt()));
        var warnIn = Math.max(0, remaining - WARN_BEFORE);

        warnTimer = setTimeout(function () {
            warningOpen = true;

            if (window.Swal) {
                Swal.fire({
                    title: warningTitle,
                    html: 'Your session will expire in <strong>1 minute</strong> due to inactivity.',
                    icon: 'warning',
                    timer: WARN_BEFORE,
                    timerProgressBar: true,
                    showCancelButton: false,
                    confirmButtonText: 'Stay Logged In',
                    confirmButtonColor: '#3085d6',
                }).then(function (result) {
                    warningOpen = false;

                    if (result.isConfirmed) {
                        markActivity();
                    }
                });
            } else if (window.appAlert) {
                // Layouts without SweetAlert (the Tailwind clinic layout) use the shared dialog.
                window.appAlert(warningTitle, 'Your session will expire in 1 minute due to inactivity.', 'warning')
                    .then(function () { warningOpen = false; markActivity(); });
            }

            logoutTimer = setTimeout(function () {
                if ((now() - lastActivityAt()) >= IDLE_LIMIT) {
                    submitLogout();
                    return;
                }

                resetTimers();
            }, WARN_BEFORE);
        }, warnIn);
    }

    function markActivity() {
        localStorage.setItem(ACTIVITY_KEY, String(now()));
        resetTimers();
    }

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart', 'pointerdown'].forEach(function (evt) {
        document.addEventListener(evt, function () {
            markActivity();
        }, { passive: true });
    });

    window.addEventListener('storage', function (event) {
        if (event.key === ACTIVITY_KEY) {
            resetTimers();
        }

        if (event.key === LOGOUT_KEY) {
            submitLogout();
        }
    });

    // A page load is activity (the server just reset its own timer), and it also clears the
    // stale timestamp left in localStorage by a previous session that timed out.
    localStorage.setItem(ACTIVITY_KEY, String(now()));

    resetTimers();
})();

// One poll for the whole page (PulseController). Widgets subscribe to the parts they show with
// appPulse.on(part, fn). Nothing is fetched while the tab is hidden; showing it polls at once.
window.appPulse = (function () {
    var INTERVAL = 20000;
    var url = '{{ route("pulse") }}';
    var handlers = {};
    var timer = null;
    var stopped = false;
    var lastPollAt = Date.now(); // the page load itself was the last server contact

    function lastActivityAt() {
        try { return parseInt(localStorage.getItem('eyeclinic:lastActivityAt') || '0', 10) || 0; } catch (e) { return 0; }
    }

    function poll() {
        var parts = Object.keys(handlers);
        if (stopped || !parts.length || document.hidden) return;

        // Tell the server whether the user has done anything since the last poll, so real
        // work keeps the session alive but an untouched tab still times out.
        var active = lastActivityAt() > lastPollAt;
        lastPollAt = Date.now();

        fetch(url + '?parts=' + parts.join(',') + (active ? '&active=1' : ''), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                // Signed out or session expired: stop; the idle-logout script handles the page.
                if (response.status === 401 || response.status === 419) { stopped = true; return null; }
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data) return;
                parts.forEach(function (part) {
                    if (!(part in data)) return;
                    handlers[part].forEach(function (fn) { try { fn(data[part]); } catch (e) {} });
                });
            })
            .catch(function () {});
    }

    function schedule(delay) {
        clearTimeout(timer);
        timer = setTimeout(function () { poll(); schedule(INTERVAL); }, delay);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && Date.now() - lastPollAt > 5000) schedule(0);
    });
    document.addEventListener('DOMContentLoaded', function () { schedule(2000); });

    return {
        on: function (part, fn) { (handlers[part] = handlers[part] || []).push(fn); },
        refresh: function () { schedule(1000); }
    };
})();

@if(auth()->user()?->hasRole(['Manager', 'Super Admin']))
(function () {
    var approveUrl = '{{ route("discount-approval-notices.approve", ["discountRequest" => "__REQUEST_ID__"]) }}';
    var rejectUrl = '{{ route("discount-approval-notices.reject", ["discountRequest" => "__REQUEST_ID__"]) }}';
    var csrfToken = '{{ csrf_token() }}';
    var activeRequestId = null;
    var isPromptOpen = false;
    var dismissedKey = 'discountApprovalNoticeDismissed';

    function dismissedIds() {
        try {
            return JSON.parse(sessionStorage.getItem(dismissedKey) || '[]');
        } catch (e) {
            return [];
        }
    }

    function markDismissed(requestId) {
        var ids = dismissedIds();
        if (ids.indexOf(requestId) === -1) {
            ids.push(requestId);
            sessionStorage.setItem(dismissedKey, JSON.stringify(ids.slice(-50)));
        }
    }

    function requestUrl(template, requestId) {
        return template.replace('__REQUEST_ID__', requestId);
    }

    function postDecision(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({})
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error(data.message || 'Unable to update discount request.');
                }

                return data;
            });
        });
    }

    function showDiscountPrompt(request) {
        activeRequestId = request.id;
        isPromptOpen = true;

        Swal.fire({
            title: 'Discount Approval Request',
            html: `
                <div class="text-start">
                    <p class="mb-2"><strong>${request.cashier_name}</strong> requested a discount for <strong>${request.patient_name}</strong>.</p>
                    <table class="table table-sm mb-0">
                        <tr>
                            <th>Full Amount:</th>
                            <td>GH\u20B5 ${request.gross_amount}</td>
                        </tr>
                        <tr>
                            <th>Discount:</th>
                            <td class="text-danger">GH\u20B5 ${request.discount_amount}</td>
                        </tr>
                        <tr>
                            <th>Final Amount:</th>
                            <td><strong>GH\u20B5 ${request.final_amount}</strong></td>
                        </tr>
                        <tr>
                            <th>Requested:</th>
                            <td>${request.created_at}</td>
                        </tr>
                    </table>
                </div>
            `,
            icon: 'warning',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            denyButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-check me-2"></i>Approve',
            denyButtonText: '<i class="fas fa-times me-2"></i>Reject',
            cancelButtonText: 'Later',
            reverseButtons: true,
            allowOutsideClick: false
        }).then(function (result) {
            if (result.isConfirmed || result.isDenied) {
                Swal.fire({
                    title: result.isConfirmed ? 'Approving...' : 'Rejecting...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });

                var url = result.isConfirmed
                    ? requestUrl(approveUrl, request.id)
                    : requestUrl(rejectUrl, request.id);

                postDecision(url)
                    .then(function (data) {
                        Swal.fire({
                            title: 'Done',
                            text: data.message,
                            icon: 'success',
                            timer: 2500,
                            showConfirmButton: false
                        });
                    })
                    .catch(function (error) {
                        Swal.fire({
                            title: 'Notice',
                            text: error.message,
                            icon: 'info'
                        });
                    })
                    .finally(function () {
                        activeRequestId = null;
                        isPromptOpen = false;
                        window.appPulse.refresh(); // show the next waiting request, if any
                    });

                return;
            }

            markDismissed(request.id);
            activeRequestId = null;
            isPromptOpen = false;
        });
    }

    window.appPulse.on('discount', function (request) {
        if (isPromptOpen || !request || request.id === activeRequestId || dismissedIds().indexOf(request.id) !== -1) {
            return;
        }

        showDiscountPrompt(request);
    });
})();
@endif
</script>
@endauth
