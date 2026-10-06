{{-- Small replacement for the Bootstrap JavaScript the converted admin screens relied on (no jQuery):
     - uiModal(id, open) shows or hides an overlay that tools/bs2tw.py made from a Bootstrap modal;
     - data-toggle="modal|collapse|dropdown" with data-target, and data-dismiss="modal", keep working;
     - Escape or a backdrop click closes an overlay opened this way (Livewire-controlled overlays are
       left to their component). Collapse and dropdown visibility are styled in clinic-ui.css. --}}
<script>
(function () {
    function target(el) {
        var selector = el.getAttribute('data-target') || el.getAttribute('href');
        return selector && selector.charAt(0) === '#' ? document.querySelector(selector) : null;
    }

    window.uiModal = function (id, open) {
        var dialog = typeof id === 'string' ? document.getElementById(id) : id;
        if (!dialog) return;
        dialog.setAttribute('data-ui-modal', '');
        dialog.classList.toggle('hidden', !open);
        dialog.classList.toggle('flex', open);
        if (open) dialog.dispatchEvent(new CustomEvent('ui-modal-shown', { bubbles: true }));
        else dialog.dispatchEvent(new CustomEvent('ui-modal-hidden', { bubbles: true }));
    };

    function closeDropdowns(except) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
            if (menu !== except) menu.classList.remove('show');
        });
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-toggle]');
        if (toggle) {
            var kind = toggle.getAttribute('data-toggle');
            if (kind === 'modal') {
                e.preventDefault();
                window.uiModal(target(toggle), true);
                return;
            }
            if (kind === 'collapse') {
                e.preventDefault();
                var panel = target(toggle);
                if (panel) {
                    var open = panel.classList.toggle('show');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                return;
            }
            if (kind === 'dropdown') {
                e.preventDefault();
                var menu = toggle.parentElement && toggle.parentElement.querySelector('.dropdown-menu');
                closeDropdowns(menu);
                if (menu) menu.classList.toggle('show');
                return;
            }
        }
        var dismiss = e.target.closest('[data-dismiss="modal"]');
        if (dismiss) {
            var overlay = dismiss.closest('.fixed.inset-0');
            if (overlay) window.uiModal(overlay, false);
            return;
        }
        // A click on an overlay's backdrop closes it, as Bootstrap's did; a click elsewhere closes menus.
        if (e.target.hasAttribute && e.target.hasAttribute('data-ui-modal') && !e.target.hasAttribute('data-static')) {
            window.uiModal(e.target, false);
        }
        if (!e.target.closest('.dropdown-menu')) closeDropdowns(null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeDropdowns(null);
        var open = Array.prototype.filter.call(document.querySelectorAll('[data-ui-modal].flex'), function (el) { return !el.classList.contains('hidden'); });
        if (open.length) window.uiModal(open[open.length - 1], false);
    });
})();
</script>
