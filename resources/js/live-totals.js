/**
 * Totals worked out in the browser while typing, instead of asking the server after every
 * keystroke. Each mirrors a server formula; the server recalculates everything when the form
 * is saved, so these only ever show a figure, never decide one.
 */

// Read numbers as the server does: blank or not a number counts as 0.
window.num = (value) => { const n = parseFloat(value); return Number.isFinite(n) ? n : 0; };
window.whole = (value) => { const n = parseInt(value, 10); return Number.isFinite(n) ? n : 0; };
window.isNumeric = (value) => value !== null && value !== undefined && String(value).trim() !== '' && ! isNaN(Number(value));
window.money = (value) => Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * New order price (OpticalOrderCreateComponent::priceBreakdown): the server's lines, with each
 * service line recounted from the quantity typed, less the discount typed.
 */
window.orderPricing = (pricing) => ({
    pricing,
    quantity(line) { return Math.max(0, whole(this.$wire.service_lines?.[line.service_index]?.quantity)); },
    isService(line) { return line.service_index !== undefined && ! this.pricing.free; },
    amount(line) { return this.isService(line) ? Math.round(line.unit * this.quantity(line) * 100) / 100 : line.amount; },
    label(line) { return this.isService(line) ? line.name + (this.quantity(line) !== 1 ? ' × ' + this.quantity(line) : '') : line.label; },
    subtotal() { return Math.round(this.pricing.lines.reduce((sum, line) => sum + this.amount(line), 0) * 100) / 100; },
    discount() { return this.pricing.free ? 0 : num(this.$wire.discount_amount); },
    total() { return this.pricing.free ? 0 : Math.max(0, Math.round((this.subtotal() - this.discount()) * 100) / 100); },
});

/**
 * Awaiting Collection: the page holds every waiting job, so search, "waiting at least" and the
 * customer filter hide rows in the browser. Rows carry data-search, -days, -partner and -balance;
 * the figures in the header follow the rows shown. `tick` moves after each server reply, so the
 * figures also follow rows the server has just added or removed.
 */
window.awaitingFilter = () => ({
    search: '',
    minDays: '0',
    partner: '',
    tick: 0,
    init() {
        if (window.Livewire?.hook) window.Livewire.hook('commit', ({ succeed }) => succeed(() => this.$nextTick(() => this.tick++)));
    },
    shows(row) {
        const term = this.search.trim().toLowerCase();
        if (term && ! row.dataset.search.includes(term)) return false;
        if (whole(row.dataset.days) < whole(this.minDays)) return false;
        if (this.partner === 'own') return row.dataset.partner === 'own';
        return this.partner === '' || row.dataset.partner === this.partner;
    },
    rows() { this.tick; return [...this.$root.querySelectorAll('tr[data-days]')].filter(row => this.shows(row)); },
    count() { return this.rows().length; },
    balance() { return this.rows().reduce((sum, row) => sum + num(row.dataset.balance), 0); },
    longest() { const days = this.rows().map(row => whole(row.dataset.days)); return days.length ? Math.max(...days) : null; },
});

/** Purchasing: a draft supplier-order line (lenses in pairs, anything else by the unit), and a return line. */
window.draftLineTotal = (line) => line?.lens_pairs ? whole(line.pairs) * num(line.pair_cost) : whole(line?.quantity) * num(line?.unit_cost);
window.linesTotal = (lines, lineTotal) => Object.values(lines ?? {}).reduce((sum, line) => sum + lineTotal(line), 0);
window.returnLineTotal = (line) => whole(line?.quantity) * num(line?.unit_cost);

/** Receiving other stock: the margin preview beside cost, price and quantity. */
window.stockMargin = ($wire, currency) => {
    if (! isNumeric($wire.unitCost) || ! isNumeric($wire.unitPrice) || ! isNumeric($wire.quantity) || num($wire.unitCost) <= 0) return '—';
    const cost = num($wire.unitCost), profit = num($wire.unitPrice) - cost;
    return `Unit Profit: ${currency} ${money(profit)} (${(profit / cost * 100).toFixed(1)}% markup) · Batch Profit: ${currency} ${money(profit * whole($wire.quantity))}`;
};

/**
 * Receiving lens stock (OpticalStockManagementComponent::receiptTotals). Reads the form from
 * $wire, so figures follow what is typed; `eyeSpecific` lists the designs stocked per eye.
 */
window.lensReceipt = (eyeSpecific = []) => ({
    columns() {
        const values = [];
        if (this.$wire.lensDesign === 'Single Vision') for (let p = 0; p >= -6; p -= 0.25) values.push(p);
        else for (let p = 0.25; p <= 4; p += 0.25) values.push(p);
        return values;
    },
    // Both-eye pairs of a progressive or bifocal are a right and a left lens.
    pairFactor() { return eyeSpecific.includes(this.$wire.lensDesign) && this.$wire.lensEye === 'B' ? 2 : 1; },
    rowTotal(r) { return Object.values(this.$wire.bulkQuantities?.[r] ?? {}).reduce((sum, q) => sum + whole(q), 0); },
    /** Powers with a quantity, for the price overrides: [{ r, c, qty, label }]. */
    entered() {
        const columns = this.columns();
        const rows = this.$wire.bulkQuantities ?? {};
        return Object.keys(rows).map(Number).sort((a, b) => a - b).flatMap(r =>
            Object.keys(rows[r] ?? {}).map(Number).sort((a, b) => a - b)
                .filter(c => whole(rows[r][c]) > 0)
                .map(c => ({ r, c, key: r + '-' + c, qty: whole(rows[r][c]),
                    label: 'SPH ' + ((-15 + r * 0.25) >= 0 ? '+' : '') + (-15 + r * 0.25).toFixed(2) + ' / ' + (columns[c] ?? 0).toFixed(2) })));
    },
    override(field, r, c) { return this.$wire[field]?.[r]?.[c] ?? ''; },
    setOverride(field, r, c, value) { this.$wire.$set(field + '.' + r + '.' + c, value, false); },
    totals() {
        const factor = this.pairFactor();
        if (this.$wire.entryMode !== 'bulk') {
            const pieces = whole(this.$wire.quantity) * factor;
            return { pieces, cost: pieces * num(this.$wire.unitCost) };
        }
        let pieces = 0, cost = 0;
        this.entered().forEach(({ r, c, qty }) => {
            const own = this.override('bulkCosts', r, c);
            pieces += qty;
            cost += qty * num(own !== '' ? own : this.$wire.unitCost);
        });
        return { pieces: pieces * factor, cost: cost * factor };
    },
});
