{{-- Toasts: fixed to the viewport so they are seen however far the page is scrolled. Placed
     first in the body so the listener exists before any page flash message fires. Errors and
     toasts with an action stay until closed; the rest close after 5 seconds unless hovered. --}}
<div x-data="{
        toasts: [],
        add(detail) {
            const toast = { id: Date.now() + Math.random(), type: 'success', ...detail };
            this.toasts = [toast, ...this.toasts].slice(0, 4);
            if (toast.type !== 'error' && ! toast.link) this.schedule(toast, 5000);
        },
        schedule(toast, ms) { clearTimeout(toast.timer); toast.timer = setTimeout(() => this.close(toast.id), ms) },
        close(id) { this.toasts = this.toasts.filter(t => t.id !== id) },
     }"
     x-on:notify.window="add($event.detail)"
     class="optical-toasts" aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="optical-toast" :class="'optical-toast--' + toast.type" :role="toast.type === 'error' ? 'alert' : 'status'"
             x-on:mouseenter="clearTimeout(toast.timer)" x-on:mouseleave="if (toast.type !== 'error' && ! toast.link) schedule(toast, 2000)">
            <span class="optical-toast__icon" aria-hidden="true" x-text="{ success: '✓', error: '!', warning: '!', info: 'i' }[toast.type] ?? 'i'"></span>
            <div class="flex-1 min-w-0">
                <p x-text="toast.message"></p>
                <a x-show="toast.link" :href="toast.link" :target="toast.newTab ? '_blank' : null" class="optical-toast__link" x-text="toast.linkLabel" x-on:click="close(toast.id)"></a>
            </div>
            <button type="button" class="optical-toast__close" aria-label="Dismiss notification" x-on:click="close(toast.id)">&times;</button>
        </div>
    </template>
</div>
