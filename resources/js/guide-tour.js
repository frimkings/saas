/**
 * Guided tours: <x-guide-tour page="..."> puts a "Tour" button on the page and, the first time a person
 * opens it, walks them round it. Each step dims the page, lights up one part (an element marked
 * data-tour="name") and explains it in a bubble; on a phone the bubble is a sheet at the bottom.
 *
 * A step whose part is not on the screen is skipped, so a tour never points at something the clinic
 * does not have. A step may name a part to click first ("open"), such as a tab. Finishing or skipping
 * is sent to the server, so the tour does not start by itself again on any device.
 */
const find = (name) => {
    for (const el of document.querySelectorAll(`[data-tour="${name}"]`)) {
        if (el.closest('.gt-root')) continue;
        const box = el.getBoundingClientRect();
        if (box.width || box.height) return el;
    }
    return null;
};

// Waits a little for a part to appear (a tab's contents arrive with Livewire's answer).
const appear = (name, ms) => new Promise((resolve) => {
    const started = Date.now();
    const look = () => {
        const el = find(name);
        if (el || Date.now() - started >= ms) return resolve(el);
        setTimeout(look, 100);
    };
    look();
});

const clamp = (value, low, high) => Math.max(low, Math.min(value, high));

// The box that scrolls the page: the app's own content area, else the window. (scrollIntoView would also
// shift wrappers that are meant never to scroll, and the layout with them.)
const scroller = (el) => {
    for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        const overflow = getComputedStyle(parent).overflowY;
        if ((overflow === 'auto' || overflow === 'scroll') && parent.scrollHeight > parent.clientHeight) return parent;
    }
    return null;
};

// Brings a part into view, leaving room for the bubble (or, on a phone, the sheet at the bottom).
const reveal = (el, mobile) => {
    const box = el.getBoundingClientRect();
    const bottom = window.innerHeight * (mobile ? 0.55 : 1) - 20;
    if (box.top >= 70 && box.bottom <= bottom) return;
    const space = bottom - 70;
    const delta = box.top - (box.height < space ? 70 + (space - box.height) / 3 : 70);
    const box2 = scroller(el);
    if (box2) box2.scrollTop += delta; else window.scrollBy(0, delta);
};

// Another window (the start-of-shift summary, a form) is open: the tour waits for it to close.
const busy = () => [...document.querySelectorAll('dialog[open], [role=dialog][aria-modal=true]')]
    .some((el) => ! el.closest('.gt-root') && el.getBoundingClientRect().width > 0);

window.guideTour = (config) => ({
    steps: config.steps,
    open: false,
    index: -1,
    step: { title: '', body: '' },
    first: true,
    last: false,
    mobile: false,
    hole: '',
    bubbleStyle: '',

    init() {
        this.onMove = () => this.place();
        if (! config.auto) return;
        let tries = 0;
        const begin = () => {
            if (this.open || ! config.auto) return;
            if (busy() && tries++ < 120) return setTimeout(begin, 1000);
            this.start();
        };
        setTimeout(begin, 700);
    },

    start() {
        if (this.open) return;
        this.open = true;
        window.addEventListener('resize', this.onMove);
        window.addEventListener('scroll', this.onMove, true);
        this.go(0, 1);
    },

    next() { this.last ? this.finish('done') : this.go(this.index + 1, 1); },
    back() { if (! this.first) this.go(this.index - 1, -1); },
    skip() { this.finish('skipped'); },

    async go(from, direction) {
        for (let i = from; i >= 0 && i < this.steps.length; i += direction) {
            const step = this.steps[i];
            if (step.open) {
                const tab = find(step.open);
                if (tab && tab.getAttribute('aria-selected') !== 'true') tab.click();
            }
            const el = step.target ? await appear(step.target, step.open ? 2500 : 300) : null;
            if (step.target && ! el) continue;
            this.index = i;
            this.step = step;
            this.first = ! this.steps.slice(0, i).some((s) => ! s.target || s.open || find(s.target));
            this.last = ! this.steps.slice(i + 1).some((s) => ! s.target || s.open || find(s.target));
            this.mobile = window.innerWidth < 640;
            if (el) reveal(el, this.mobile);
            this.place();
            this.$nextTick(() => { this.place(); document.querySelector('.gt-root .gt-next')?.focus(); });
            return;
        }
        if (direction > 0) this.finish('done');
    },

    place() {
        if (! this.open) return;
        this.mobile = window.innerWidth < 640;
        const el = this.step.target ? find(this.step.target) : null;
        if (! el) {
            this.hole = '';
            this.bubbleStyle = this.mobile ? '' : 'top:50%;left:50%;transform:translate(-50%,-50%);width:360px';
            return;
        }
        const box = el.getBoundingClientRect();
        const pad = 6;
        this.hole = `top:${box.top - pad}px;left:${box.left - pad}px;width:${box.width + pad * 2}px;height:${box.height + pad * 2}px`;
        if (this.mobile) { this.bubbleStyle = ''; return; }
        const width = 340;
        const height = document.querySelector('.gt-root .gt-bubble')?.offsetHeight || 190;
        const gap = 14;
        let top = box.bottom + gap;
        if (top + height > window.innerHeight - 8 && box.top - gap - height > 8) top = box.top - gap - height;
        top = clamp(top, 8, window.innerHeight - height - 8);
        const left = clamp(box.left, 8, window.innerWidth - width - 8);
        this.bubbleStyle = `top:${top}px;left:${left}px;width:${width}px`;
    },

    finish(status) {
        this.open = false;
        window.removeEventListener('resize', this.onMove);
        window.removeEventListener('scroll', this.onMove, true);
        if (! config.url) return;
        config.auto = false;
        fetch(config.url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        }).catch(() => {});
    },
});
