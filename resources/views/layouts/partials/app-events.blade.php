{{-- Browser events some components dispatch for the page to handle:
     - show-delete-confirmation {id, method?}: confirm, then call the component's method (default confirmDelete);
     - printRefraction {html}: open the refraction print view in a new window. --}}
<script>
(function () {
    window.addEventListener('show-delete-confirmation', function (event) {
        var id = event.detail && event.detail.id;
        var method = (event.detail && event.detail.method) || 'confirmDelete';
        window.appConfirm("You won't be able to undo this.", { title: 'Are you sure?', confirmText: 'Yes, delete it', danger: true })
            .then(function (ok) { if (ok) Livewire.dispatch(method, { id: id }); });
    });

    window.addEventListener('printRefraction', function (event) {
        var printWindow = window.open('', '_blank');
        if (!printWindow) {
            window.appAlert('Pop-up blocked', 'Please allow pop-ups for this site to print.', 'warning');
            return;
        }
        printWindow.document.write(event.detail.html);
        printWindow.document.close();
    });
})();
</script>
