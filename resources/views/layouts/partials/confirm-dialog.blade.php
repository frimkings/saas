{{-- Styled confirmation for every `wire:confirm` and for window.appConfirm(message) calls.
     Uses SweetAlert2 where a layout already loads it (clinic and platform), otherwise the
     built-in dialog below (optical). Optional attributes on the confirming element:
     data-confirm-title, data-confirm-button, data-confirm-danger="true|false". --}}
<div x-data="{
        open: false, title: '', body: '', confirmText: 'Confirm', danger: false, resolve: null,
        show(detail) {
            Object.assign(this, detail, { open: true });
            this.$nextTick(() => (this.danger ? this.$refs.back : this.$refs.ok).focus());
        },
        answer(ok) { this.open = false; this.resolve && this.resolve(ok); this.resolve = null },
     }"
     x-on:app-confirm.window="show($event.detail)"
     x-on:keydown.escape.window="open && answer(false)">
    <template x-if="open">
        <div class="app-confirm" x-on:click.self="answer(false)">
            <div class="app-confirm__box" role="alertdialog" aria-modal="true" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-body">
                <div class="app-confirm__icon" :class="danger && 'app-confirm__icon--danger'" aria-hidden="true" x-text="danger ? '!' : '?'"></div>
                <h2 id="app-confirm-title" class="app-confirm__title" x-text="title"></h2>
                <p id="app-confirm-body" class="app-confirm__body" x-show="body" x-text="body"></p>
                <div class="app-confirm__actions">
                    <button type="button" x-ref="back" class="app-confirm__btn" x-on:click="answer(false)">Go back</button>
                    <button type="button" x-ref="ok" class="app-confirm__btn app-confirm__btn--ok" :class="danger && 'app-confirm__btn--danger'" x-on:click="answer(true)" x-text="confirmText"></button>
                </div>
            </div>
        </div>
    </template>
</div>
<style>
.app-confirm { position: fixed; inset: 0; z-index: 2000; display: grid; place-items: center; padding: 16px; background: rgb(15 23 42 / 55%); }
.app-confirm__box { width: 100%; max-width: 420px; padding: 24px; border-radius: 14px; background: #fff; color: #0f172a; text-align: center; box-shadow: 0 24px 48px rgb(15 23 42 / 25%); font-family: inherit; }
.app-confirm__icon { width: 44px; height: 44px; margin: 0 auto 12px; border-radius: 999px; display: grid; place-items: center; font-size: 22px; font-weight: 700; color: #0f766e; background: #ccfbf1; }
.app-confirm__icon--danger { color: #b91c1c; background: #fee2e2; }
.app-confirm__title { margin: 0; font-size: 17px; font-weight: 700; line-height: 1.35; }
.app-confirm__body { margin: 8px 0 0; font-size: 14px; line-height: 1.5; color: #475569; }
.app-confirm__actions { margin-top: 20px; display: flex; gap: 8px; justify-content: center; }
.app-confirm__btn { min-width: 110px; padding: 9px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font-size: 14px; font-weight: 600; cursor: pointer; }
.app-confirm__btn:hover { background: #f1f5f9; }
.app-confirm__btn--ok { border-color: #0f766e; background: #0f766e; color: #fff; }
.app-confirm__btn--ok:hover { background: #115e59; }
.app-confirm__btn--danger { border-color: #b91c1c; background: #b91c1c; }
.app-confirm__btn--danger:hover { background: #991b1b; }
.app-confirm__btn:focus-visible { outline: 3px solid #5eead4; outline-offset: 2px; }
</style>
<script>
    (() => {
        const DANGER = /\b(delete|remove|reverse|void|refund|archive|trash|discard|clear|reset|deactivate|suspend|revoke|cancel|reject|release|write off)\b/i;
        const VERBS = /^(delete|remove|reverse|void|refund|archive|discard|clear|reset|deactivate|suspend|revoke|reject|release|approve|process|send|re-send|resend|move|restore|collect|complete|mark|convert|apply|activate|close|submit|confirm)\b/i;

        /** "Delete this claim? This cannot be undone." -> title + body; the verb names the button. */
        function describe(message, el) {
            message = String(message || 'Are you sure?').replaceAll('\\n', '\n').trim();
            const split = message.match(/^([^?]*\?)\s+([\s\S]+)$/);
            const title = el?.dataset.confirmTitle || (split ? split[1] : message);
            const body = el?.dataset.confirmTitle ? message : (split ? split[2] : '');
            const verb = title.match(VERBS)?.[1];
            const danger = el?.dataset.confirmDanger ? el.dataset.confirmDanger === 'true' : DANGER.test(message);
            const confirmText = el?.dataset.confirmButton || (verb && ! /^cancel$/i.test(verb) ? verb[0].toUpperCase() + verb.slice(1).toLowerCase() : (danger ? 'Yes, continue' : 'Confirm'));
            return { title, body, confirmText, danger };
        }

        window.appConfirm = (message, el = null) => new Promise(resolve => {
            const d = describe(message, el);
            if (window.Swal) {
                window.Swal.fire({
                    title: d.title, text: d.body || undefined, icon: d.danger ? 'warning' : 'question',
                    showCancelButton: true, confirmButtonText: d.confirmText, cancelButtonText: 'Go back',
                    confirmButtonColor: d.danger ? '#b91c1c' : '#0f766e', reverseButtons: true, focusCancel: d.danger,
                }).then(result => resolve(result.isConfirmed));
                return;
            }
            window.dispatchEvent(new CustomEvent('app-confirm', { detail: { ...d, resolve } }));
        });

        // Livewire's wire:confirm uses the browser's blocking confirm(). Replace it after Livewire
        // sets it up: stop the click now, and run the action only once the user confirms.
        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('directive.init', ({ el, directive }) => {
                if (directive.value !== 'confirm' || directive.modifiers.includes('prompt')) return;
                el.__livewire_confirm = (action, instead) => {
                    instead();
                    window.appConfirm(directive.expression, el).then(ok => ok && action());
                };
            });
        });
    })();
</script>
