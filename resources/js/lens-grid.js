/**
 * Picking powers on the lens blank power grid, entirely in the browser. Ticking cells, blocks,
 * rows, "all out / all low" and the running estimate need no server call; the server is asked
 * once, to build the purchase order or the order sheet, and checks every power again then.
 *
 * The grid data comes from the root element's data-grid attribute, which Livewire refreshes
 * with the page: { cells: { "sphere|power": [pairs, extra, extraEye, reorder, onOrder, pairCost] },
 * typical: typical pair cost for a power the range has never stocked }.
 */
window.lensGrid = (view = 'pairs') => ({
    view,
    selecting: false,
    selected: {},
    last: null,
    includeOnOrder: false,
    pairsEach: 10,
    _raw: null,
    _grid: { cells: {}, typical: 0 },

    grid() {
        const raw = this.$root.dataset.grid || '{}';
        if (raw !== this._raw) {
            this._raw = raw;
            try { this._grid = Object.assign({ cells: {}, typical: 0 }, JSON.parse(raw)); } catch (e) { this._grid = { cells: {}, typical: 0 }; }
        }
        return this._grid;
    },

    stocked(key) { return Object.prototype.hasOwnProperty.call(this.grid().cells, key); },
    isSel(key) { return this.selected[key] === true; },
    keys() { return Object.keys(this.selected); },
    parse(key) { return key.split('|').map(Number); },
    add(keys) { const next = { ...this.selected }; keys.forEach(k => { next[k] = true; }); this.selected = next; },

    toggleMode() { this.selecting = ! this.selecting; this.clear(); },

    /** A cell click: pick it while selecting, otherwise open Receive stock for that power. */
    pick(key, shift = false) {
        if (this.selecting) return this.toggle(key, shift);
        const url = this.$root.dataset.receiveUrl;
        if (! url) return;
        const [sphere, power] = key.split('|');
        window.location.href = url + '&lensSphere=' + encodeURIComponent(sphere) + '&lensPower=' + encodeURIComponent(power);
    },

    /** Coming back to the browser tab refreshes the stock, at most once a minute. */
    _refreshedAt: Date.now(),
    refreshIfStale() {
        if (document.visibilityState !== 'visible' || Date.now() - this._refreshedAt < 60000) return;
        this._refreshedAt = Date.now();
        this.$wire.$refresh();
    },
    clear() { this.selected = {}; this.last = null; },

    /** One power; with Shift, every stocked power in the rectangle from the last click. Never-stocked powers can be picked singly. */
    toggle(key, shift = false) {
        if (shift && this.last && this.stocked(this.last) && this.stocked(key)) {
            const [s1, p1] = this.parse(this.last), [s2, p2] = this.parse(key);
            this.add(Object.keys(this.grid().cells).filter(k => {
                const [s, p] = this.parse(k);
                return s >= Math.min(s1, s2) && s <= Math.max(s1, s2) && p >= Math.min(p1, p2) && p <= Math.max(p1, p2);
            }));
        } else {
            const next = { ...this.selected };
            next[key] ? delete next[key] : (next[key] = true);
            this.selected = next;
        }
        this.last = key;
    },

    /** A whole SPH row or CYL/ADD column of stocked powers; clicking again clears it. */
    line(axis, value) {
        const index = axis === 'sphere' ? 0 : 1;
        const keys = Object.keys(this.grid().cells).filter(k => this.parse(k)[index] === Number(value));
        if (keys.length && keys.every(k => this.selected[k])) {
            const next = { ...this.selected };
            keys.forEach(k => delete next[k]);
            this.selected = next;
        } else {
            this.add(keys);
        }
    },

    /** out = no pair left (red cells); low = at or below the reorder level. Powers already on order are skipped unless included. */
    byStock(which) {
        const cells = this.grid().cells;
        this.add(Object.keys(cells).filter(k => {
            const [pairs, extra, extraEye, reorder, onOrder] = cells[k];
            if (onOrder && ! this.includeOnOrder) return false;
            const out = pairs === 0 && ! (extra && extraEye === null);
            return which === 'out' ? out : (! out && pairs <= reorder);
        }));
    },

    count() { return this.keys().length; },
    pairsTotal() { return this.count() * Math.max(0, parseInt(this.pairsEach, 10) || 0); },
    estimate() {
        const { cells, typical } = this.grid();
        const perPair = this.keys().reduce((sum, k) => sum + (cells[k] ? Number(cells[k][5]) : Number(typical)), 0);
        return perPair * Math.max(0, parseInt(this.pairsEach, 10) || 0);
    },
    money(value) { return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
});
