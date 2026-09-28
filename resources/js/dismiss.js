/**
 * Close a modal, drawer or panel in the browser, with no server call.
 *
 *   <button x-on:click="dismissLocal($el, $wire, 'showForm')">Cancel</button>
 *   <button x-on:click="dismissLocal($el, $wire, { viewOrderId: null })">Close</button>
 *
 * The nearest [data-sheet] around the button, or else the nearest <dialog> or [role=dialog], is closed and taken
 * out of the page, and the properties are set on $wire without a request. The server hears
 * of them with the component's next request, where an updated…() hook can run anything else
 * closing needs (clearing errors, say). The page that request draws no longer has the
 * panel; removing it now (rather than hiding it) lets the next "open" draw it afresh.
 */
window.dismissLocal = (el, $wire, properties, value = false) => {
    const changes = typeof properties === 'string' ? { [properties]: value } : properties;
    // A marked wrapper (a backdrop around the dialog, say) wins over the dialog inside it.
    const panel = el.closest('[data-sheet]') ?? el.closest('dialog, [role=dialog]');
    // A panel that stays in the page (data-keep) hides itself through x-show on these properties.
    if (panel && ! panel.hasAttribute('data-keep')) {
        if (panel.tagName === 'DIALOG' && panel.open) panel.close();
        // A drawer's overlay is drawn just before it.
        const overlay = panel.previousElementSibling;
        if (overlay?.hasAttribute('data-sheet-overlay')) overlay.remove();
        panel.remove();
    }
    Object.entries(changes).forEach(([name, next]) => $wire.$set(name, next, false));
};

/**
 * Open a form that stays in the page (data-keep, shown by x-show="$wire.showForm"), filled in
 * from values the page already has, with no server call. wire:model fields show the values at
 * once; Save sends them. Errors left from an earlier attempt are hidden (is-fresh) until the
 * next server reply, which redraws the panel without that class.
 *
 *   <button x-on:click="openLocal($wire, { editingId: 4, name: 'Frames', showForm: true }, $refs.form)">Edit</button>
 */
window.openLocal = ($wire, values, panel = null) => {
    Object.entries(values).forEach(([name, value]) => $wire.$set(name, value, false));
    if (! panel) return;
    panel.classList.add('is-fresh');
    requestAnimationFrame(() => panel.querySelector('input:not([type=hidden]), select, textarea')?.focus());
};

/**
 * Close a panel whose state the browser may not change itself (a #[Locked] id, a model):
 * it goes at once, and the server's close method runs in the background. Give that method
 * #[Renderless] so the reply redraws nothing.
 *
 *   <button x-on:click="dismissCall($el, $wire, 'closeCount')">Close</button>
 */
window.dismissCall = (el, $wire, method) => {
    window.dismissLocal(el, $wire, {});
    $wire.$call(method);
};
