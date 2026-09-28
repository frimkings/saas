/**
 * The expense form, opened, filled and closed in the browser. Recording a new expense, editing
 * one (its details come from the row's data-expense attribute) or recording a repeating bill
 * that is due (data-recurring) needs no server call until Save, which sends the form in one
 * call: the server checks everything again and refreshes the list in the same response.
 */
const blank = (today) => ({
    expense_date: today, amount: '', payee: '', expense_category_id: '', description: '',
    payment_method: '', reference: '', notes: '', repeat: false, frequency: 'monthly',
});

window.expenseForm = (today) => ({
    open: false,
    saving: false,
    // Hides errors left in the page by an earlier attempt until this form is saved.
    fresh: true,
    id: null,
    recurringId: null,
    receiptUrl: null,
    hasFile: false,
    form: blank(today),

    show(values = {}, meta = {}) {
        this.form = { ...blank(today), ...values };
        this.id = meta.id ?? null;
        this.recurringId = meta.recurringId ?? null;
        this.receiptUrl = meta.receiptUrl ?? null;
        this.hasFile = false;
        this.fresh = true;
        if (this.$refs.file) this.$refs.file.value = '';
        this.open = true;
        this.$nextTick(() => this.$refs.amount?.focus());
    },
    create() { this.show(); },
    edit(el) {
        const data = JSON.parse(el.dataset.expense || '{}');
        this.show(data.form, { id: data.id, receiptUrl: data.receiptUrl });
    },
    recordDue(el) {
        const data = JSON.parse(el.dataset.recurring || '{}');
        this.show(data.form, { recurringId: data.id });
    },
    close() { if (! this.saving) this.open = false; },

    async save() {
        if (this.saving) return;
        this.saving = true;
        this.fresh = false;
        try {
            const saved = await this.$wire.saveExpense(this.form, this.id, this.recurringId, this.hasFile);
            if (saved === true) this.open = false;
        } finally {
            this.saving = false;
        }
    },

    async remove(el) {
        if (! this.id || ! (await window.appConfirm('Delete this expense?', el))) return;
        await this.$wire.deleteExpense(this.id);
        this.open = false;
    },

    async removeReceipt(el) {
        if (! this.id || ! (await window.appConfirm('Remove this receipt?', el))) return;
        await this.$wire.deleteReceipt(this.id);
        this.receiptUrl = null;
    },
});
