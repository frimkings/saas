/**
 * Every drop-down with more than a few choices can be searched. Clicking one opens a small panel
 * with a search box over its choices instead of the browser's own list. The <select> stays the
 * real field (Livewire and forms read it): choosing sets its value and fires input and change,
 * so wire:model, wire:change and x-model all follow. The keyboard still works on the <select>
 * itself, and phones and tablets keep their own pickers. A select marked data-plain is left alone.
 */
const MIN_OPTIONS = 6;
let open = null; // { panel, select, items, active }

const fold = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

function close(refocus = false) {
    if (! open) return;
    const { panel, select } = open;
    panel.remove();
    open = null;
    if (refocus) select.focus();
}

function choose(option) {
    const { select } = open;
    close(true);
    if (select.value === option.value) return;
    select.value = option.value;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

function render(term) {
    const { panel, select } = open;
    const list = panel.querySelector('.ss-list');
    const words = fold(term).split(/\s+/).filter(Boolean);
    list.replaceChildren();
    open.items = [];
    let group = null;
    for (const option of select.options) {
        if (option.hidden || option.disabled) continue;
        const text = option.textContent.trim();
        if (words.length && ! words.every((word) => fold(text).includes(word))) continue;
        const label = option.parentElement.tagName === 'OPTGROUP' ? option.parentElement.label : null;
        if (label && label !== group) {
            const heading = document.createElement('li');
            heading.className = 'ss-group';
            heading.textContent = label;
            list.append(heading);
        }
        group = label;
        const item = document.createElement('li');
        item.className = 'ss-item' + (option.value === select.value ? ' ss-chosen' : '');
        item.setAttribute('role', 'option');
        item.textContent = text || ' ';
        item.addEventListener('mousedown', (event) => { event.preventDefault(); choose(option); });
        item.addEventListener('mousemove', () => highlight(open.items.indexOf(item)));
        list.append(item);
        open.items.push(item);
        item.option = option;
    }
    if (! open.items.length) {
        const none = document.createElement('li');
        none.className = 'ss-none';
        none.textContent = 'Nothing matches';
        list.append(none);
    }
    const chosen = open.items.findIndex((item) => item.option.value === select.value);
    highlight(words.length ? 0 : Math.max(0, chosen));
}

function highlight(index) {
    if (! open || ! open.items.length) return;
    open.active = Math.max(0, Math.min(index, open.items.length - 1));
    open.items.forEach((item, i) => item.classList.toggle('ss-active', i === open.active));
    open.items[open.active].scrollIntoView({ block: 'nearest' });
}

function place(panel, select) {
    const box = select.getBoundingClientRect();
    const width = Math.max(box.width, 240);
    const below = window.innerHeight - box.bottom;
    const height = Math.min(320, Math.max(below, box.top) - 12);
    panel.style.width = width + 'px';
    panel.style.left = Math.max(8, Math.min(box.left, window.innerWidth - width - 8)) + 'px';
    panel.style.maxHeight = height + 'px';
    if (below >= Math.min(320, box.top) || below > 200) {
        panel.style.top = (box.bottom + 4) + 'px';
    } else {
        panel.style.top = Math.max(8, box.top - height - 4) + 'px';
    }
}

function show(select) {
    close();
    const panel = document.createElement('div');
    panel.className = 'ss-panel';
    panel.innerHTML = '<input type="search" class="ss-search" placeholder="Search…" aria-label="Search the choices" autocomplete="off"><ul class="ss-list" role="listbox"></ul>';
    // Inside an open dialog the panel must sit in the dialog too, or the dialog would cover it.
    (select.closest('dialog[open]') || document.body).append(panel);
    open = { panel, select, items: [], active: 0 };
    place(panel, select);
    const search = panel.querySelector('.ss-search');
    search.addEventListener('input', () => render(search.value));
    search.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') { event.preventDefault(); highlight(open.active + 1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(open.active - 1); }
        else if (event.key === 'Enter') { event.preventDefault(); if (open.items[open.active]) choose(open.items[open.active].option); }
        else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
        else if (event.key === 'Tab') { close(); }
    });
    render('');
    search.focus();
}

function wanted(select) {
    return select && ! select.multiple && ! select.disabled && select.size <= 1 && ! ('plain' in select.dataset)
        && select.options.length >= MIN_OPTIONS && window.matchMedia('(pointer: fine)').matches;
}

document.addEventListener('mousedown', (event) => {
    const select = event.target.closest?.('select');
    if (open && ! open.panel.contains(event.target) && select !== open.select) close();
    if (! wanted(select)) return;
    event.preventDefault();
    if (open?.select === select) { close(true); return; }
    select.focus();
    show(select);
}, true);

// Opening from the keyboard: Alt+Down, as browsers do for their own list.
document.addEventListener('keydown', (event) => {
    const select = event.target;
    if (select?.tagName === 'SELECT' && event.altKey && event.key === 'ArrowDown' && wanted(select)) {
        event.preventDefault();
        show(select);
    }
}, true);

window.addEventListener('resize', () => close());
document.addEventListener('scroll', (event) => { if (open && ! open.panel.contains(event.target)) close(); }, true);
// A page change or a Livewire redraw that removes the field closes the panel.
document.addEventListener('livewire:navigating', () => close());
setInterval(() => { if (open && ! open.select.isConnected) close(); }, 500);
