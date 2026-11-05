{{-- The business and branch this page was opened in. Every tab shares one session, so after a switch in
     another tab this page would otherwise save into the new business. Livewire calls send it as a header and
     forms as a field; App\Http\Middleware\ResolveTenantContext refuses a change that no longer matches. --}}
@auth
@php $tenant = app(\App\Support\Tenancy\TenantContext::class); @endphp
@if($tenant->resolved())
<meta name="tenant-context" content="{{ $tenant->clinicId() }}:{{ $tenant->branchId() }}">
<script data-navigate-once>
(function () {
    var HEADER = 'X-Tenant-Context', FIELD = '_tenant_context';
    // Read when sending: wire:navigate swaps the head, so the stamp follows the page in view.
    function stamp() {
        var metas = document.querySelectorAll('meta[name="tenant-context"]');
        return metas.length ? metas[metas.length - 1].content : '';
    }
    var warned = false;
    function warn(message) {
        if (warned) return;
        warned = true;
        var title = 'This page is for another business';
        var ask = window.appConfirm
            ? window.appConfirm(message, { title: title, confirmText: 'Reload page', danger: false })
            : Promise.resolve(window.confirm(title + '\n\n' + message));
        ask.then(function (reload) { warned = false; if (reload) window.location.reload(); });
    }

    document.addEventListener('livewire:init', function () {
        Livewire.hook('request', function (request) {
            request.options.headers = request.options.headers || {};
            request.options.headers[HEADER] = stamp();
            request.fail(function (failure) {
                if (failure.status !== 409) return;
                failure.preventDefault();
                warn(tenantMessage(failure.content));
            });
        });
    });

    function tenantMessage(body) {
        try { return JSON.parse(body).message; } catch (e) { return 'You switched business or branch in another tab. Nothing was saved. Reload this page to carry on there.'; }
    }

    // Plain forms (POST, PUT, DELETE) carry it as a field.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'get').toLowerCase() === 'get') return;
        var input = form.querySelector('input[name="' + FIELD + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = FIELD;
            form.appendChild(input);
        }
        input.value = stamp();
    }, true);
})();
</script>
@endif
@endauth
