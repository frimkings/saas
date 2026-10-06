"""Bootstrap 4 (+ a few Bootstrap 5 names) -> Tailwind 3 class converter for Blade views.

Rewrites class tokens inside class="..." and Alpine :class / x-bind:class values. Component
classes some page CSS still targets (card, card-header, card-body, btn, form-control, table,
list-group-item) are kept as plain hooks next to their Tailwind replacement.
Usage: python bs2tw.py file1.blade.php [file2 ...]   (rewrites in place, prints a summary)
"""
import re
import sys
from collections import Counter

BP = ['sm', 'md', 'lg', 'xl']
SPACE = {'0': '0', '1': '1', '2': '2', '3': '4', '4': '6', '5': '12', 'auto': 'auto'}
M = {}

# Spacing: m/p, sides, responsive, negative (BS4 mt-n1)
for prop in 'mp':
    for side in ['', 't', 'b', 'l', 'r', 'x', 'y']:
        for size, tw in SPACE.items():
            if prop == 'p' and size == 'auto':
                continue
            M[f'{prop}{side}-{size}'] = f'{prop}{side}-{tw}'
            for bp in BP:
                M[f'{prop}{side}-{bp}-{size}'] = f'{bp}:{prop}{side}-{tw}'
            if prop == 'm' and size not in ('0', 'auto'):
                M[f'{prop}{side}-n{size}'] = f'-{prop}{side}-{tw}'
# Bootstrap 5 logical spacing seen in some views
for size, tw in SPACE.items():
    M[f'me-{size}'] = f'mr-{tw}'
    M[f'ms-{size}'] = f'ml-{tw}'
    if size != 'auto':
        M[f'pe-{size}'] = f'pr-{tw}'
        M[f'ps-{size}'] = f'pl-{tw}'

# Display
for d, tw in {'none': 'hidden', 'block': 'block', 'inline': 'inline', 'inline-block': 'inline-block', 'flex': 'flex',
              'inline-flex': 'inline-flex', 'grid': 'grid', 'table': 'table', 'table-cell': 'table-cell', 'table-row': 'table-row'}.items():
    M[f'd-{d}'] = tw
    for bp in BP:
        M[f'd-{bp}-{d}'] = f'{bp}:{tw}'

# Flex
FLEX = {'flex-column': 'flex-col', 'flex-row': 'flex-row', 'flex-wrap': 'flex-wrap', 'flex-nowrap': 'flex-nowrap',
        'flex-grow-1': 'grow', 'flex-grow-0': 'grow-0', 'flex-shrink-0': 'shrink-0', 'flex-shrink-1': 'shrink', 'flex-fill': 'flex-1',
        'flex-column-reverse': 'flex-col-reverse', 'flex-row-reverse': 'flex-row-reverse'}
for k, v in FLEX.items():
    M[k] = v
    for bp in BP:
        M[k.replace('flex-', f'flex-{bp}-', 1)] = f'{bp}:{v}'
for a in ['start', 'end', 'center', 'between', 'around']:
    M[f'justify-content-{a}'] = f'justify-{a}'
    for bp in BP:
        M[f'justify-content-{bp}-{a}'] = f'{bp}:justify-{a}'
for a in ['start', 'end', 'center', 'baseline', 'stretch']:
    M[f'align-items-{a}'] = f'items-{a}'
    M[f'align-self-{a}'] = f'self-{a}'
    M[f'align-content-{a}'] = f'content-{a}'
    for bp in BP:
        M[f'align-items-{bp}-{a}'] = f'{bp}:items-{a}'
for a in ['top', 'middle', 'bottom']:
    M[f'align-{a}'] = f'align-{a}'

# Text
for a in ['left', 'right', 'center']:
    M[f'text-{a}'] = f'text-{a}'
    for bp in BP:
        M[f'text-{bp}-{a}'] = f'{bp}:text-{a}'
M.update({'text-end': 'text-right', 'text-start': 'text-left',
          'font-weight-bold': 'font-semibold', 'font-weight-bolder': 'font-bold', 'font-weight-normal': 'font-normal',
          'font-weight-light': 'font-light', 'font-weight-semibold': 'font-semibold', 'fw-bold': 'font-semibold', 'fw-semibold': 'font-semibold',
          'fw-normal': 'font-normal', 'fw-medium': 'font-medium', 'font-italic': 'italic', 'text-uppercase': 'uppercase',
          'text-lowercase': 'lowercase', 'text-capitalize': 'capitalize', 'text-nowrap': 'whitespace-nowrap', 'text-truncate': 'truncate',
          'text-break': 'break-words', 'text-wrap': 'whitespace-normal', 'small': 'text-sm', 'lead': 'text-lg',
          'text-monospace': 'font-mono', 'text-decoration-none': 'no-underline',
          'h1': 'text-3xl font-semibold', 'h2': 'text-2xl font-semibold', 'h3': 'text-xl font-semibold',
          'h4': 'text-lg font-semibold', 'h5': 'text-base font-semibold', 'h6': 'text-sm font-semibold'})

COLOURS = {'primary': 'teal-700', 'secondary': 'slate-500', 'success': 'green-700', 'danger': 'red-700', 'warning': 'amber-600',
           'info': 'sky-700', 'dark': 'slate-900', 'muted': 'slate-500', 'light': 'slate-100', 'white': 'white', 'body': 'slate-800', 'purple': 'violet-700'}
for k, v in COLOURS.items():
    M[f'text-{k}'] = f'text-{v}'
M['text-white-50'] = 'text-white/70'
M['text-black-50'] = 'text-black/50'
BG = {'primary': 'bg-teal-700 text-white', 'secondary': 'bg-slate-500 text-white', 'success': 'bg-green-600 text-white',
      'danger': 'bg-red-600 text-white', 'warning': 'bg-amber-400', 'info': 'bg-sky-600 text-white', 'dark': 'bg-slate-800 text-white',
      'light': 'bg-slate-50', 'white': 'bg-white', 'transparent': 'bg-transparent'}
for k, v in BG.items():
    M[f'bg-{k}'] = v
BORDERC = {'primary': 'teal-600', 'secondary': 'slate-400', 'success': 'green-500', 'danger': 'red-500', 'warning': 'amber-400',
           'info': 'sky-500', 'dark': 'slate-800', 'light': 'slate-100', 'white': 'white'}
for k, v in BORDERC.items():
    M[f'border-{k}'] = f'border-{v}'
M.update({'border': 'border border-slate-200', 'border-top': 'border-t border-slate-200', 'border-bottom': 'border-b border-slate-200',
          'border-left': 'border-l border-slate-200', 'border-right': 'border-r border-slate-200', 'border-0': 'border-0',
          'border-top-0': 'border-t-0', 'border-bottom-0': 'border-b-0', 'border-left-0': 'border-l-0', 'border-right-0': 'border-r-0',
          'rounded': 'rounded-md', 'rounded-circle': 'rounded-full', 'rounded-pill': 'rounded-full', 'rounded-0': 'rounded-none',
          'rounded-sm': 'rounded', 'rounded-lg': 'rounded-lg',
          'w-25': 'w-1/4', 'w-50': 'w-1/2', 'w-75': 'w-3/4', 'w-100': 'w-full', 'h-100': 'h-full', 'mw-100': 'max-w-full',
          'vh-100': 'h-screen', 'min-vh-100': 'min-h-screen',
          'position-relative': 'relative', 'position-absolute': 'absolute', 'position-fixed': 'fixed', 'position-sticky': 'sticky',
          'position-static': 'static', 'sticky-top': 'sticky top-0 z-20', 'fixed-top': 'fixed inset-x-0 top-0 z-30',
          'overflow-hidden': 'overflow-hidden', 'overflow-auto': 'overflow-auto',
          'opacity-75': 'opacity-75', 'opacity-50': 'opacity-50', 'float-right': 'float-right', 'float-left': 'float-left',
          'clearfix': 'clearfix', 'invisible': 'invisible', 'visible': 'visible', 'cursor-pointer': 'cursor-pointer',
          'img-fluid': 'h-auto max-w-full', 'list-unstyled': 'list-none pl-0', 'list-inline': 'list-none pl-0',
          'list-inline-item': 'inline-block mr-2', 'gap-1': 'gap-1', 'gap-2': 'gap-2', 'gap-3': 'gap-4'})

# Grid
M.update({'row': 'flex flex-wrap -mx-2', 'form-row': 'flex flex-wrap -mx-2', 'no-gutters': 'mx-0', 'g-0': '', 'g-2': '', 'g-3': '',
          'col': 'min-w-0 flex-1 px-2', 'col-auto': 'w-auto px-2', 'container-fluid': 'w-full', 'container': 'mx-auto w-full'})
for n in range(1, 13):
    M[f'col-{n}'] = ('w-full' if n == 12 else f'w-{n}/12') + ' px-2 __col__'
    for bp in BP:
        M[f'col-{bp}-{n}'] = '__wfull__ ' + f'{bp}:' + ('w-full' if n == 12 else f'w-{n}/12') + ' px-2 __col__'
for bp in BP:
    M[f'col-{bp}'] = f'w-full {bp}:w-auto {bp}:flex-1 px-2 __col__'
    M[f'col-{bp}-auto'] = f'w-full {bp}:w-auto px-2 __col__'

# Components (hooks kept where page CSS targets them)
M.update({
    'btn': 'btn ui-button', 'btn-sm': 'ui-button-sm', 'btn-xs': 'ui-button-sm', 'btn-lg': '', 'btn-block': 'w-full',
    'btn-primary': 'ui-button-primary', 'btn-success': 'ui-button-primary', 'btn-info': 'ui-button-primary',
    'btn-danger': 'ui-button-danger', 'btn-outline-danger': 'ui-button-danger',
    'btn-link': 'ui-button-link', 'btn-group': 'inline-flex flex-wrap gap-1', 'btn-group-sm': '', 'btn-toolbar': 'flex flex-wrap gap-2',
    'form-control': 'form-control ui-input', 'form-control-sm': 'ui-input-sm', 'form-control-lg': '', 'form-control-plaintext': 'block w-full py-2',
    'custom-select': 'ui-input', 'custom-select-sm': 'ui-input-sm', 'custom-file-input': 'block w-full text-sm', 'custom-file-label': 'hidden',
    'custom-file': 'block', 'form-group': 'mb-4', 'form-text': 'mt-1 block text-xs text-slate-500', 'form-label': 'mb-1 block text-xs font-semibold text-slate-600',
    'col-form-label': 'mb-1 block text-xs font-semibold text-slate-600', 'invalid-feedback': 'ui-error', 'valid-feedback': 'mt-1 text-sm text-green-700',
    'form-check': 'flex items-center gap-2', 'form-check-inline': 'mr-4 inline-flex items-center gap-2', 'form-check-input': 'rounded border-slate-300 text-teal-700',
    'form-check-label': '', 'form-switch': '', 'form-select': 'ui-input', 'form-select-sm': 'ui-input-sm',
    'custom-control': 'flex items-center gap-2', 'custom-control-inline': 'mr-4 inline-flex', 'custom-checkbox': '', 'custom-radio': '', 'custom-switch': '',
    'custom-control-input': 'rounded border-slate-300 text-teal-700', 'custom-control-label': '',
    'input-group': 'flex items-stretch', 'input-group-sm': '', 'input-group-prepend': 'flex', 'input-group-append': 'flex',
    'input-group-text': 'flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600',
    'card': 'card overflow-hidden rounded-xl border border-slate-200 bg-white', 'card-header': 'card-header border-b border-slate-200 bg-slate-50 px-4 py-2',
    'card-body': 'card-body p-4', 'card-footer': 'border-t border-slate-200 bg-slate-50 px-4 py-2', 'card-title': 'font-semibold', 'card-text': '',
    'card-outline': '', 'card-tools': 'ml-auto flex items-center gap-1',
    'badge': 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold', 'badge-pill': 'rounded-full',
    'badge-primary': 'bg-teal-100 text-teal-800', 'badge-secondary': 'bg-slate-100 text-slate-700', 'badge-success': 'bg-green-100 text-green-800',
    'badge-danger': 'bg-red-100 text-red-800', 'badge-warning': 'bg-amber-100 text-amber-800', 'badge-info': 'bg-sky-100 text-sky-800',
    'badge-light': 'bg-slate-50 text-slate-600', 'badge-dark': 'bg-slate-800 text-white',
    'alert': 'rounded-lg border px-3 py-2 text-sm', 'alert-info': 'border-sky-200 bg-sky-50 text-sky-900', 'alert-warning': 'border-amber-200 bg-amber-50 text-amber-900',
    'alert-danger': 'border-red-200 bg-red-50 text-red-800', 'alert-success': 'border-green-200 bg-green-50 text-green-800',
    'alert-secondary': 'border-slate-200 bg-slate-50 text-slate-700', 'alert-light': 'border-slate-200 bg-white text-slate-700',
    'alert-primary': 'border-teal-200 bg-teal-50 text-teal-900', 'alert-dark': 'border-slate-700 bg-slate-800 text-white',
    'alert-link': 'font-semibold underline', 'alert-dismissible': '', 'alert-heading': 'font-semibold',
    'table': 'table ui-table', 'table-sm': 'ui-table-sm', 'table-bordered': '', 'table-hover': '', 'table-striped': '', 'table-borderless': '',
    'thead-light': '', 'thead-dark': '', 'table-light': '', 'table-responsive': 'ui-table-wrap',
    'table-success': 'bg-green-50', 'table-warning': 'bg-amber-50', 'table-danger': 'bg-red-50', 'table-info': 'bg-sky-50', 'table-active': 'bg-slate-100',
    'list-group': 'overflow-hidden rounded-md border border-slate-200 bg-white', 'list-group-flush': '',
    'list-group-item': 'list-group-item block w-full border-b border-slate-100 px-3 py-2 text-left', 'list-group-item-action': 'hover:bg-slate-50',
    'nav': 'flex flex-wrap', 'nav-tabs': 'border-b border-slate-200', 'nav-pills': 'gap-1', 'nav-item': '', 'nav-link': 'block px-3 py-2',
    'close': 'text-xl leading-none text-slate-500 hover:text-slate-800', 'btn-close': 'text-xl leading-none text-slate-500 hover:text-slate-800',
    'spinner-border': 'inline-block h-5 w-5 animate-spin rounded-full border-2 border-current border-r-transparent', 'spinner-border-sm': '!h-3.5 !w-3.5',
    'progress': 'h-2 overflow-hidden rounded-full bg-slate-200', 'progress-bar': 'h-full bg-teal-600',
    'shadow-sm': 'shadow-sm', 'shadow': 'shadow', 'shadow-lg': 'shadow-lg', 'shadow-none': 'shadow-none',
    'text-xs': 'text-xs', 'text-sm': 'text-sm', 'elevation-1': 'shadow', 'elevation-2': 'shadow-md',
})
# Modals: a Livewire-conditional modal (`modal show` / `d-block`) becomes a visible overlay; one
# opened by jQuery (`modal fade` alone) starts hidden and needs its open/close script rewired.
M.update({
    'modal': '__modal__ fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4',
    'fade': '', 'modal-backdrop': 'fixed inset-0 bg-slate-900/50',
    'modal-dialog': 'mx-auto my-8 w-full max-w-lg', 'modal-sm': 'max-w-sm', 'modal-lg': 'max-w-3xl', 'modal-xl': 'max-w-6xl',
    'modal-dialog-centered': '', 'modal-dialog-scrollable': '',
    'modal-content': 'overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl',
    'modal-header': 'flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3',
    'modal-title': 'text-base font-semibold', 'modal-body': 'p-4',
    'modal-footer': 'flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3',
})
for v in ['secondary', 'light', 'default', 'white', 'dark', 'warning', 'outline-primary', 'outline-secondary', 'outline-info', 'outline-success',
          'outline-dark', 'outline-light', 'outline-warning']:
    M[f'btn-{v}'] = 'ui-button-secondary'

TOKEN = re.compile(r'(?<![\w$:./\[-])([a-z][a-z0-9-]*)(?![\w/\]-])')
VARIANTS = ('ui-button-primary', 'ui-button-secondary', 'ui-button-danger', 'ui-button-link')


def convert_value(value, stats):
    def sub(m):
        tok = m.group(1)
        if tok in M:
            rep = M[tok]
            if rep != tok:
                stats[tok] += 1
            return rep
        return tok
    out = TOKEN.sub(sub, value)
    return out


def tidy_static(value):
    """Whole static class lists: fix col widths, add a button variant, drop duplicates and blanks."""
    if '{{' in value or '@' in value:
        return value.replace('__wfull__', 'w-full').replace('__col__', '').replace('__modal__ ', '')
    toks = value.split()
    base_width = any(re.fullmatch(r'w-(\d+/12|full)', t) for t in toks if t != 'w-full') or any(
        re.fullmatch(r'w-\d+/12', t) for t in toks)
    toks = [('' if base_width else 'w-full') if t == '__wfull__' else t for t in toks]
    toks = [t for t in toks if t and t != '__col__']
    if '__modal__' in toks:
        toks.remove('__modal__')
        if 'show' not in toks and 'block' not in toks:
            toks.append('hidden')
    if 'ui-button' in toks and not any(t in VARIANTS for t in toks):
        toks.append('ui-button-secondary')
    # One value per family: a later class (usually the author's explicit utility) beats a
    # component default added earlier, e.g. card's bg-white vs bg-light, or p-4 vs p-0.
    last = {}
    for i, t in enumerate(toks):
        fam = family(t)
        if fam:
            last[fam] = i
    toks = [t for i, t in enumerate(toks) if not family(t) or last[family(t)] == i]
    seen, out = set(), []
    for t in toks:
        if t not in seen:
            seen.add(t)
            out.append(t)
    return ' '.join(out)


COLOUR = r'(?:slate|gray|teal|green|red|amber|sky|blue|violet|orange|yellow)-\d+|white|black|transparent'


def family(tok):
    if ':' in tok or tok.startswith('!'):
        return None
    if re.fullmatch(rf'bg-(?:{COLOUR})', tok):
        return 'bg'
    if re.fullmatch(rf'text-(?:{COLOUR})(?:/\d+)?', tok):
        return 'text-colour'
    if re.fullmatch(rf'border-(?:{COLOUR})', tok):
        return 'border-colour'
    m = re.fullmatch(r'-?([mp])([trblxy]?)-(?:\d+(?:\.\d+)?|auto|px)', tok)
    if m:
        return m.group(1) + (m.group(2) or 'all')
    if re.fullmatch(r'rounded(?:-(?:none|sm|md|lg|xl|2xl|full))?', tok):
        return 'rounded'
    if re.fullmatch(r'max-w-(?:xs|sm|md|lg|xl|[2-7]xl|full|none)', tok):
        return 'max-w'
    return None


ATTR = re.compile(r'(\s(?:class|:class|x-bind:class)=)"((?:[^"\\]|\\.)*)"', re.S)


def convert(text, stats):
    def attr(m):
        name, value = m.group(1), m.group(2)
        if 'class=' in name and not name.strip().startswith((':', 'x-bind')):
            new = tidy_static(convert_value(value, stats))
        else:  # Alpine: only rewrite quoted class strings inside the expression
            new = re.sub(r"'([^'\\]*)'", lambda q: "'" + tidy_static(convert_value(q.group(1), stats)) + "'", value)
        return f'{name}"{new}"'
    return ATTR.sub(attr, text)


if __name__ == '__main__':
    total = Counter()
    for path in sys.argv[1:]:
        stats = Counter()
        src = open(path, encoding='utf-8').read()
        out = convert(src, stats)
        open(path, 'w', encoding='utf-8').write(out)
        total.update(stats)
        print(f'{path}: {sum(stats.values())} class tokens converted')
    print('top:', ', '.join(f'{k}={v}' for k, v in total.most_common(25)))
