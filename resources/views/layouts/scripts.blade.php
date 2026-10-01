    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $routeTitles = [
            // Admin
            'admin.dashboard'               => 'Admin Dashboard',
            'admin.clinical-task-center'    => 'Clinical Task Center',
            'admin.offline-health'          => 'Offline Health Dashboard',
            'admin.category'                => 'Categories',
            'admin.product'                 => 'Products',
            'admin.suppliers'               => 'Suppliers',
            'admin.settings'                => 'Settings',
            'admin.users'                   => 'User Management',
            'admin.reports'                 => 'Reports',
            'admin.sales-records'           => 'Sales Records',
            'admin.income-statement'        => 'Income Statement',
            'admin.diagnoses'               => 'Diagnoses',
            'admin.inventory-alerts'        => 'Inventory Alerts',
            'admin.stock-movements'         => 'Stock Movements',
            'admin.daily-cash-summary'      => 'Daily Cash Summary',
            'admin.audit-trail'             => 'Audit Trail',
            'admin.login-history'           => 'Login History',
            'admin.discount-approvals'      => 'Discount Approvals',
            'admin.approvals'                      => 'Approvals',
            'admin.refund-approvals'               => 'Refund Approvals',
            'admin.clearance-revoke-approvals'    => 'Clearance Revoke Approvals',
            'admin.password-reset-approvals'      => 'Password Reset Approvals',
            'admin.roles-permissions'       => 'Roles & Permissions',

            // Secretary
            'secretary.dashboard'           => 'Secretary Dashboard',
            'secretary.patients'            => 'Patients',
            'secretary.appointments'        => 'Appointments',
            'secretary.spectacles'          => 'Spectacles Orders',
            'secretary.patient-clearance'   => 'Patient Clearance',

            // Cashier / POS
            'cashier.seller-desk'           => 'Point of Sale',
            'cashier.outstanding-balances'  => 'Outstanding Balances',
            'cashier.sales-records'         => 'Sales Records',
            'cashier.receipt.show'          => 'Receipt',
            'refunds.logs'                  => 'Refund Logs',
            'cart'                          => 'Cart',

            // Doctor
            'doctor.dashboard'              => 'Doctor Dashboard',
            'doctor.patient-awaiting'       => 'Patients Awaiting',
            'doctor.patient-records'        => 'Patient Records',
            'doctor.all-records'            => 'All Records',
            'doctor.referrals'              => 'Referrals',
            'doctor.patient-timeline'       => 'Patient Timeline',

            // Shared
            'user.profile'                  => 'My Profile',
            'staff.messages'                => 'Messages',
            'dashboard'                     => 'Dashboard',
        ];
        $pageTitle = $routeTitles[\Illuminate\Support\Facades\Route::currentRouteName() ?? ''] ?? null;
        try {
            $clinicName = \App\Models\Setting::getSettings()->clinic_name ?? config('app.name');
        } catch (\Throwable $e) {
            $clinicName = config('app.name');
        }
    @endphp
    <title>{{ $pageTitle ? $pageTitle . ' - ' : '' }}{{ $clinicName }}</title>



  
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/nunito-local.css') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('backend/plugins/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/dist/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/plugins/toastr/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/plugins/sweetalert2/sweetalert2.min.css') }}">

  
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/bootstrap-tagsinput.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/pikaday.css') }}">

    <link rel="stylesheet" href="{{ asset('backend/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css') }}">

    @livewireStyles
    @livewireScripts


<script src="{{ asset('backend/plugins/jquery/jquery.min.js') }}"></script>


<script src="{{ asset('backend/plugins/vendor-js/moment.min.js') }}"></script>

<script src="{{ asset('backend/dist/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('backend/dist/js/adminlte.min.js') }}"></script>
<script src="{{ asset('backend/plugins/vendor-js/select2.min.js') }}"></script>
<script>
    // Suppress Alpine duplicate loading error that breaks JavaScript execution
    window.addEventListener('error', function(e) {
        if (e.message && e.message.includes('started')) {
            console.log('⚠️ Alpine error suppressed (known Livewire issue)');
            e.stopImmediatePropagation();
            e.preventDefault();
            return false;
        }
    }, true);
    console.log('✅ Error suppression active');
</script>

<script src="{{ asset('backend/plugins/toastr/toastr.min.js') }}"></script>
<script src="{{ asset('backend/plugins/sweetalert2/sweetalert2.js') }}"></script>


<script src="{{ asset('backend/plugins/vendor-js/bootstrap-tagsinput.min.js') }}"></script>
<script src="{{ asset('backend/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js') }}"></script>
<script src="{{ asset('backend/plugins/vendor-js/pikaday.js') }}"></script>
<script src="{{ asset('backend/plugins/vendor-js/daterangepicker.min.js') }}"></script>
<script src="{{ asset('backend/plugins/vendor-js/chart.min.js') }}"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        console.log('✅ Layout JavaScript Loading');

       
        toastr.options = {
            closeButton: true,
            progressBar: true,
            positionClass: "toast-top-right",
            timeOut: "5000"
        };

    
        const modalEvents = [
            'addPatientModal',
            'addClearanceModal',
            'addSpectaclePrescriptionModal',
            'addOderPrescriptionModal',
            'addAppointmentModal',
            'confirmationModal',
            'addConsultationModal',
            'addCategoryModal',
            'addProductModal',
        ];

        modalEvents.forEach(modalId => {
            window.addEventListener(`show-${modalId}-form`, () => {
                $(`#${modalId}`).modal('show');
            });

            window.addEventListener(`hide-${modalId}-modal`, event => {
                $(`#${modalId}`).modal('hide');
                if (event.detail?.message) {
                    toastr.success(event.detail.message, 'Success');
                }
            });
        });

        
        window.addEventListener('show-addRefractionModal-form', () => {
            $('#addRefractionModal').modal('show');
        });

        window.addEventListener('hide-addRefractionModal-modal', event => {
            $('#addRefractionModal').modal('hide');
            if (event.detail?.message) {
                toastr.success(event.detail.message, 'Success');
            }
        });

        // DELETE / CONFIRMATION MODALS
        window.addEventListener('show-delete-confirmation', event => {
            const id = event.detail?.id;
            const method = event.detail?.method || 'confirmDelete';
            window.appConfirm("You won't be able to undo this.", { title: 'Are you sure?', confirmText: 'Yes, delete it', danger: true })
                .then(ok => ok && Livewire.dispatch(method, { id: id }));
        });

        // Refund confirmation
        ['confirmRefund', 'confirm-refund'].forEach(eventName => {
            window.addEventListener(eventName, () => {
                window.appConfirm('Are you sure you want to refund this sale?', { title: 'Confirm refund', confirmText: 'Yes, refund it', danger: true })
                    .then(ok => ok && Livewire.dispatch(eventName === 'confirmRefund' ? 'confirmRefund' : 'initiateRefund'));
            });
        });

        // CHECKOUT CONFIRMATION (POS)
        window.addEventListener('confirm-sell-without-pending-discount', event => {
            const details = event.detail || {};

            Swal.fire({
                title: 'Discount approval pending',
                html: `
                    <div class="text-start">
                        <p class="mb-2">This cart has a discount waiting for Manager/Super Admin approval.</p>
                        <table class="table table-sm mb-0">
                            <tr>
                                <th>Full Amount:</th>
                                <td>GHâ‚µ ${details.fullAmount || '0.00'}</td>
                            </tr>
                            <tr>
                                <th>Pending Discount:</th>
                                <td class="text-danger">-GHâ‚µ ${details.discountAmount || '0.00'}</td>
                            </tr>
                            <tr>
                                <th>Discounted Amount:</th>
                                <td>GHâ‚µ ${details.discountedAmount || '0.00'}</td>
                            </tr>
                        </table>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-tag me-2"></i>Sell Without Discount',
                cancelButtonText: '<i class="fas fa-clock me-2"></i>Wait for Approval',
                reverseButtons: true,
                didOpen: () => {
                    const html = Swal.getHtmlContainer();
                    if (html) {
                        html.innerHTML = html.innerHTML.replace(/GH\S*\s/g, 'GH\u20B5 ');
                    }
                }
            }).then(result => {
                if (!result.isConfirmed) {
                    return;
                }

                window.dispatchEvent(new CustomEvent('sell-without-pending-discount'));
            });
        });

        window.addEventListener('show-checkout-confirmation', event => {
            const details = event.detail;
            console.log('Checkout confirmation event received:', details);
            
            Swal.fire({
                title: 'Confirm Sale',
                html: `
                    <div class="text-start">
                        <table class="table table-sm">
                            <tr>
                                <th>Items:</th>
                                <td>${details.itemCount}</td>
                            </tr>
                            ${details.insurerName ? `
                            <tr>
                                <th>Bill Total:</th>
                                <td>{{ currency() }} ${details.billTotal}</td>
                            </tr>
                            <tr>
                                <th>Billed to ${details.insurerName}:</th>
                                <td>{{ currency() }} ${details.insurerAmount}</td>
                            </tr>` : ''}
                            <tr>
                                <th>${details.insurerName ? 'Patient Pays:' : 'Total Amount:'}</th>
                                <td>{{ currency() }} ${details.totalAmount}</td>
                            </tr>
                            <tr>
                                <th>Amount Paid:</th>
                                <td>{{ currency() }} ${details.amountPaid}</td>
                            </tr>
                            <tr class="table-success">
                                <th>Change:</th>
                                <td><strong>{{ currency() }} ${details.change}</strong></td>
                            </tr>
                        </table>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-check me-2"></i>Confirm & Process',
                cancelButtonText: '<i class="fas fa-times me-2"></i>Cancel',
                reverseButtons: true,
                didOpen: () => {
                    const html = Swal.getHtmlContainer();
                    if (html) {
                        html.innerHTML = html.innerHTML.replace(/GH\S*\s/g, 'GH\u20B5 ');
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    console.log('User confirmed, showing processing loader...');
                    
                    Swal.fire({
                        title: 'Processing Sale...',
                        html: 'Please wait while we process your transaction',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    console.log('Calling checkout method...');
                    
                    // Trigger checkout once through Livewire. The POS component's
                    // idempotency key remains the server-side retry safeguard.
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('confirmCheckout');
                    } else {
                        console.error('Livewire not found!');
                    }
                }
            });
        });

        // GENERIC NOTIFICATIONS
        window.addEventListener('notify', event => {
            const { type, message } = event.detail || {};
            if (type && message) {
                switch (type) {
                    case 'success': 
                        toastr.success(message, 'Success'); 
                        break;
                    case 'error': 
                        toastr.error(message, 'Error');
                        Swal.close();
                        break;
                    case 'info': 
                        toastr.info(message, 'Info'); 
                        break;
                    case 'warning': 
                        toastr.warning(message, 'Warning'); 
                        break;
                    default: 
                        toastr.info(message);
                }
            }
        });
        
        // Close processing modal event
        window.addEventListener('close-processing-modal', event => {
            console.log('✓ Close processing modal event in layout');
            Swal.close();
        });

        // PRINT RECEIPT (Generic - for other pages if needed)
        window.addEventListener('alert-receipt', e => {
            Swal.fire({
                title: 'Sale Completed',
                text: e.detail.message,
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: 'Print Receipt',
                cancelButtonText: 'Close'
            }).then(res => {
                if (res.isConfirmed) {
                    const receiptContent = document.getElementById('receipt-content');
                    const win = window.open('', 'Print Receipt', 'height=600,width=400');

                    if (!receiptContent || !win) {
                        return;
                    }

                    win.document.title = 'Receipt';
                    win.document.body.replaceChildren(receiptContent.cloneNode(true));
                    win.print();
                }
            });
        });

        // Print Refraction Results
        window.addEventListener('printRefraction', event => {
            let printWindow = window.open('', '_blank');
            printWindow.document.write(event.detail.html);
            printWindow.document.close();
        });



        console.log('✅ Layout JavaScript Loaded Successfully');
    });
</script>

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
