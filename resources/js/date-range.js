// Date range picker for list/report filters: presets on the left, one month on the right.
// Used through the <x-date-range> Blade component, which writes to two Livewire properties.
// Picking a preset, or the second day of a range, applies straight away (one request).

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const SHORT = MONTHS.map((m) => m.slice(0, 3));

// Dates are handled as local calendar days ("YYYY-MM-DD"), never as instants, so the
// clinic's "today" (sent by the server) is never shifted by the browser's time zone.
const parse = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
};
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);

function presetRange(key, today) {
    const y = today.getFullYear(), m = today.getMonth();
    const startOfWeek = addDays(today, -((today.getDay() + 6) % 7)); // Monday
    const q = Math.floor(m / 3) * 3;
    const ranges = {
        today: [today, today],
        yesterday: [addDays(today, -1), addDays(today, -1)],
        tomorrow: [addDays(today, 1), addDays(today, 1)],
        last3: [addDays(today, -2), today],
        last7: [addDays(today, -6), today],
        last15: [addDays(today, -14), today],
        last30: [addDays(today, -29), today],
        last90: [addDays(today, -89), today],
        next7: [today, addDays(today, 6)],
        next30: [today, addDays(today, 29)],
        this_week: [startOfWeek, today],
        this_month: [new Date(y, m, 1), today],
        this_month_full: [new Date(y, m, 1), new Date(y, m + 1, 0)],
        last_month: [new Date(y, m - 1, 1), new Date(y, m, 0)],
        this_quarter: [new Date(y, q, 1), today],
        ytd: [new Date(y, 0, 1), today],
        last_year: [new Date(y - 1, 0, 1), new Date(y - 1, 11, 31)],
    };
    return ranges[key] || null;
}

function dateRange(config) {
    return {
        fromProp: config.from,
        toProp: config.to,
        presets: config.presets,           // [[key, label], ...]
        today: parse(config.today),
        min: parse(config.min),
        max: parse(config.max),
        clearable: config.clearable,
        open: false,
        from: null,                        // applied range (Date)
        to: null,
        anchor: null,                      // first click of a new range
        hover: null,
        view: null,                        // first day of the month on screen
        focus: null,                       // keyboard focus day
        align: config.align || 'left',
        panelStyle: '',

        init() {
            this.readFromWire();
            // Other controls (e.g. "Reset filters") can change the properties.
            this.$wire.$watch(this.fromProp, () => this.readFromWire());
            this.$wire.$watch(this.toProp, () => this.readFromWire());
        },

        readFromWire() {
            this.from = parse(this.$wire.get(this.fromProp));
            this.to = parse(this.$wire.get(this.toProp));
            // The closed panel's template still reads the month and weeks, so always have a month.
            if (!this.open) {
                const base = this.from || this.today;
                this.view = new Date(base.getFullYear(), base.getMonth(), 1);
            }
        },

        toggle() { this.open ? this.close() : this.show(); },

        show() {
            const base = this.from || this.today;
            this.view = new Date(base.getFullYear(), base.getMonth(), 1);
            this.anchor = null;
            this.hover = null;
            this.focus = this.from || this.today;
            this.open = true;
            this.$nextTick(() => {
                this.place();
                this.$refs.grid?.querySelector('[tabindex="0"]')?.focus();
            });
        },

        // The panel is fixed to the window so panels with overflow:hidden can't clip it.
        // It opens below the button, or above when there isn't room.
        place() {
            if (!this.open || !this.$refs.trigger || !this.$refs.panel) return;
            if (window.matchMedia('(max-width: 600px)').matches) { this.panelStyle = ''; return; }
            const t = this.$refs.trigger.getBoundingClientRect();
            const p = this.$refs.panel.getBoundingClientRect();
            const gap = 6, margin = 8;
            let top = t.bottom + gap;
            if (top + p.height > window.innerHeight - margin && t.top - gap - p.height > margin) top = t.top - gap - p.height;
            let left = this.align === 'right' ? t.right - p.width : t.left;
            left = Math.max(margin, Math.min(left, window.innerWidth - p.width - margin));
            this.panelStyle = `top:${Math.round(top)}px;left:${Math.round(left)}px`;
        },

        close(returnFocus = true) {
            this.open = false;
            this.anchor = null;
            if (returnFocus) this.$refs.trigger?.focus();
        },

        apply(from, to) {
            if (to < from) [from, to] = [to, from];
            this.from = from;
            this.to = to;
            // Set the first property without a request, the second with one: a single round trip.
            this.$wire.set(this.fromProp, iso(from), false);
            this.$wire.set(this.toProp, iso(to));
            this.close();
        },

        clear() {
            this.from = this.to = null;
            this.$wire.set(this.fromProp, '', false);
            this.$wire.set(this.toProp, '');
            this.close();
        },

        choosePreset(key) {
            const range = presetRange(key, this.today);
            if (range) this.apply(this.clamp(range[0]), this.clamp(range[1]));
        },

        clamp(d) {
            if (this.min && d < this.min) return this.min;
            if (this.max && d > this.max) return this.max;
            return d;
        },

        activePreset() {
            if (!this.from || !this.to) return null;
            const found = this.presets.find(([key]) => {
                const r = presetRange(key, this.today);
                return r && iso(this.clamp(r[0])) === iso(this.from) && iso(this.clamp(r[1])) === iso(this.to);
            });
            return found ? found[0] : null;
        },

        pick(day) {
            if (this.disabled(day)) return;
            if (!this.anchor) {
                this.anchor = day;
                this.focus = day;
                return;
            }
            this.apply(this.anchor, day);
        },

        // Calendar grid: 6 weeks starting on Sunday, like the reference design.
        weeks() {
            const first = this.view;
            const start = addDays(first, -first.getDay());
            return Array.from({ length: 6 }, (_, w) => Array.from({ length: 7 }, (_, d) => addDays(start, w * 7 + d)));
        },

        monthLabel() { return `${MONTHS[this.view.getMonth()]} ${this.view.getFullYear()}`; },
        shiftMonth(n) {
            this.view = new Date(this.view.getFullYear(), this.view.getMonth() + n, 1);
            this.focus = new Date(this.view);
        },

        inMonth(d) { return d.getMonth() === this.view.getMonth(); },
        isToday(d) { return iso(d) === iso(this.today); },
        disabled(d) { return (this.min && d < this.min) || (this.max && d > this.max); },

        // The range being shown: the applied one, or anchor..hover while picking.
        shown() {
            if (this.anchor) {
                const other = this.hover || this.anchor;
                return this.anchor <= other ? [this.anchor, other] : [other, this.anchor];
            }
            return this.from && this.to ? [this.from, this.to] : null;
        },
        isStart(d) { const r = this.shown(); return r && iso(d) === iso(r[0]); },
        isEnd(d) { const r = this.shown(); return r && iso(d) === iso(r[1]); },
        inRange(d) { const r = this.shown(); return r && d >= r[0] && d <= r[1]; },

        dayClasses(d) {
            const range = this.shown();
            const inRange = this.inRange(d);
            const weekday = d.getDay();
            return {
                'is-muted': !this.inMonth(d),
                'is-disabled': this.disabled(d),
                'is-today': this.isToday(d),
                'in-range': inRange,
                'is-start': this.isStart(d),
                'is-end': this.isEnd(d),
                'is-single': range && iso(range[0]) === iso(range[1]) && inRange,
                // Round the shaded bar at week edges.
                'row-start': inRange && (weekday === 0 || this.isStart(d)),
                'row-end': inRange && (weekday === 6 || this.isEnd(d)),
            };
        },

        dayLabel(d) {
            return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}${this.isToday(d) ? ', today' : ''}${this.isStart(d) ? ', range start' : ''}${this.isEnd(d) ? ', range end' : ''}`;
        },

        isFocus(d) { return this.focus && iso(d) === iso(this.focus); },

        onKey(e) {
            const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
            if (e.key in moves) {
                e.preventDefault();
                this.focus = addDays(this.focus || this.today, moves[e.key]);
                if (this.focus.getMonth() !== this.view.getMonth() || this.focus.getFullYear() !== this.view.getFullYear()) {
                    this.view = new Date(this.focus.getFullYear(), this.focus.getMonth(), 1);
                }
                if (this.anchor) this.hover = this.focus;
                this.$nextTick(() => this.$refs.grid?.querySelector('[tabindex="0"]')?.focus());
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (this.focus) this.pick(this.focus);
            } else if (e.key === 'PageUp' || e.key === 'PageDown') {
                e.preventDefault();
                this.shiftMonth(e.key === 'PageUp' ? -1 : 1);
                this.$nextTick(() => this.$refs.grid?.querySelector('[tabindex="0"]')?.focus());
            }
        },

        label() {
            if (!this.from || !this.to) return config.placeholder;
            const sameYear = this.from.getFullYear() === this.to.getFullYear();
            const showYear = !sameYear || this.from.getFullYear() !== this.today.getFullYear();
            const fmt = (d, year) => `${SHORT[d.getMonth()]} ${d.getDate()}${year ? `, ${d.getFullYear()}` : ''}`;
            if (iso(this.from) === iso(this.to)) return fmt(this.from, showYear);
            return `${fmt(this.from, showYear && !sameYear)} – ${fmt(this.to, showYear)}`;
        },

        iso,
    };
}

window.dateRange = dateRange;
document.addEventListener('alpine:init', () => window.Alpine.data('dateRange', dateRange));
