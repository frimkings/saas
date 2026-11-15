{{-- Browser events some components dispatch for the page to handle:
     - show-delete-confirmation {id, method?}: confirm, then call the component's method (default confirmDelete); --}}
<script>
(function () {
    window.addEventListener('show-delete-confirmation', function (event) {
        var id = event.detail && event.detail.id;
        var method = (event.detail && event.detail.method) || 'confirmDelete';
        window.appConfirm("You won't be able to undo this.", { title: 'Are you sure?', confirmText: 'Yes, delete it', danger: true })
            .then(function (ok) { if (ok) Livewire.dispatch(method, { id: id }); });
    });

})();
</script>
