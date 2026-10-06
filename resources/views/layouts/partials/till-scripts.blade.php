{{-- Till pop-ups (SweetAlert) for the clinic layout: POS checkout and pending-discount confirmations,
     and closing the "processing" loader. --}}
<script>
(function () {
    var posMoney = function (value) { return @js(currency()) + ' ' + (value ?? '0.00'); };
    var posRows = function (rows) {
        return '<table class="w-full text-left text-sm">' + rows.map(function (row) {
            return '<tr class="border-b border-slate-100 ' + (row[2] || '') + '"><th class="py-1.5 pr-3 font-medium text-slate-600">' + row[0] + '</th><td class="py-1.5 text-right">' + row[1] + '</td></tr>';
        }).join('') + '</table>';
    };

    window.addEventListener('confirm-sell-without-pending-discount', function (event) {
        var d = event.detail || {};
        Swal.fire({
            title: 'Discount approval pending',
            html: '<p class="mb-2 text-sm">This cart has a discount waiting for Manager/Super Admin approval.</p>' + posRows([
                ['Full amount', posMoney(d.fullAmount)],
                ['Pending discount', '-' + posMoney(d.discountAmount), 'text-red-700'],
                ['Discounted amount', posMoney(d.discountedAmount)],
            ]),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0f766e',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sell without discount',
            cancelButtonText: 'Wait for approval',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed) window.dispatchEvent(new CustomEvent('sell-without-pending-discount'));
        });
    });

    window.addEventListener('show-checkout-confirmation', function (event) {
        var d = event.detail || {};
        var rows = [['Items', d.itemCount]];
        if (d.insurerName) {
            rows.push(['Bill total', posMoney(d.billTotal)]);
            rows.push(['Billed to ' + String(d.insurerName).replace(/[<>&"]/g, ''), posMoney(d.insurerAmount)]);
        }
        rows.push([d.insurerName ? 'Patient pays' : 'Total amount', posMoney(d.totalAmount)]);
        rows.push(['Amount paid', posMoney(d.amountPaid)]);
        rows.push(['Change', '<strong>' + posMoney(d.change) + '</strong>', 'bg-green-50']);

        Swal.fire({
            title: 'Confirm sale',
            html: posRows(rows),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0f766e',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Confirm & process',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
        }).then(function (result) {
            if (!result.isConfirmed) return;
            Swal.fire({
                title: 'Processing sale…',
                html: 'Please wait while we process your transaction',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () { Swal.showLoading(); },
            });
            // The POS component's idempotency key remains the server-side retry safeguard.
            Livewire.dispatch('confirmCheckout');
        });
    });

    window.addEventListener('close-processing-modal', function () { window.Swal && Swal.close(); });
    window.addEventListener('notify', function (event) {
        if ((event.detail || {}).type === 'error' && window.Swal) Swal.close();
    });
})();
</script>
