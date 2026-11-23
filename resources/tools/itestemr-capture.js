/*
 * Nexus App: iTestEMR capture.
 * Run while signed in to the clinic's iTestEMR (any page of it). It reads, with that login, the patient
 * directory, every patient's "Print All Records" page, the stock lists and the staff list, and downloads
 * one .zip that Legacy Imports -> From other software -> iTestEMR takes as it is. It only reads pages:
 * nothing in iTestEMR is changed. The platform turns this file into the bookmark button.
 */
(async () => {
  if (window.__nexusCapture) { alert('The Nexus capture is already running in this tab.'); return; }
  // A big clinic is copied in stages: patients by number range (one .zip each), then the lists on their own.
  const answer = prompt('Nexus capture: what should be copied?\n\n'
    + 'ALL: everything in one go (clinics with up to about 5,000 patients).\n'
    + 'A range such as 1-2000: only those patients\' files, by iTestEMR\'s internal number. Run 2001-4000 next, and so on (about 45 minutes per 2,000 on a slow server).\n'
    + 'LISTS: stock, staff, prices, payments, balances and spectacle orders, without patient files.', 'ALL');
  if (answer === null) return;
  const rangeMatch = answer.trim().match(/^(\d+)\s*-\s*(\d+)$/);
  const mode = rangeMatch ? 'range' : (/^lists?$/i.test(answer.trim()) ? 'lists' : 'all');
  if (mode === 'all' && !/^all$/i.test(answer.trim())) { alert('Type ALL, LISTS or a range such as 1-2000.'); return; }
  const range = rangeMatch ? [Math.min(+rangeMatch[1], +rangeMatch[2]), Math.max(+rangeMatch[1], +rangeMatch[2])] : null;
  if (range && range[1] - range[0] > 9999) { alert('Copy at most 10,000 numbers per stage, for example 1-2000.'); return; }
  window.__nexusCapture = true;

  const host = location.hostname;
  const today = new Date().toISOString().slice(0, 10);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  let stopped = false;

  /* ---------- progress panel ---------- */
  const panel = document.createElement('div');
  panel.setAttribute('style', 'position:fixed;z-index:2147483647;right:16px;bottom:16px;width:380px;max-width:calc(100vw - 32px);background:#0f172a;color:#e2e8f0;font:13px/1.45 system-ui,sans-serif;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.35);padding:14px 16px');
  panel.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><strong style="font-size:14px">Nexus App · iTestEMR capture</strong><button type="button" data-stop style="background:#334155;color:#fff;border:0;border-radius:6px;padding:4px 10px;cursor:pointer">Stop</button></div>'
    + '<div data-step style="margin-top:8px;color:#93c5fd">Starting…</div>'
    + '<div style="height:8px;background:#1e293b;border-radius:99px;overflow:hidden;margin:8px 0"><div data-bar style="height:100%;width:0;background:#38bdf8;transition:width .3s"></div></div>'
    + '<div data-log style="max-height:180px;overflow:auto;color:#cbd5e1;font-size:12px"></div>'
    + '<div style="margin-top:8px;color:#94a3b8;font-size:11px">Keep this tab open and in front until the download starts. Nothing in iTestEMR is changed.</div>';
  document.body.appendChild(panel);
  const $ = (s) => panel.querySelector(s);
  $('[data-stop]').onclick = () => { stopped = true; step('Stopping…'); };
  const step = (text, pct) => { $('[data-step]').textContent = text; if (pct !== undefined) $('[data-bar]').style.width = Math.max(0, Math.min(100, pct)) + '%'; };
  const log = (text, bad) => { const p = document.createElement('div'); p.textContent = (bad ? '⚠ ' : '✓ ') + text; if (bad) p.style.color = '#fbbf24'; $('[data-log]').appendChild(p); $('[data-log]').scrollTop = 1e6; report.push((bad ? 'WARNING: ' : '') + text); };
  const report = [];
  const check = () => { if (stopped) throw new Error('Stopped by you. Nothing was downloaded.'); };

  /* ---------- reading pages ---------- */
  const SignedOut = new Error('iTestEMR signed you out. Sign in again and click the button once more.');
  async function getDoc(path) {
    const r = await fetch(path, { credentials: 'same-origin' });
    if (r.redirected && /\/login\b/.test(r.url)) throw SignedOut;
    if (!r.ok) { const e = new Error(path + ' answered ' + r.status); e.status = r.status; throw e; }
    return new DOMParser().parseFromString(await r.text(), 'text/html');
  }

  /** Opens a page in a hidden frame so its Livewire parts run (load more, pages, live input values). */
  function frame(path) {
    return new Promise((resolve, reject) => {
      const f = document.createElement('iframe');
      f.setAttribute('style', 'position:fixed;left:-10000px;top:0;width:1200px;height:900px;border:0;opacity:0;pointer-events:none');
      f.src = path;
      const timer = setTimeout(() => { f.remove(); reject(new Error(path + ' did not load in time')); }, 90000);
      f.onload = async () => {
        try {
          if (/\/login\b/.test(f.contentWindow.location.pathname)) { clearTimeout(timer); f.remove(); reject(SignedOut); return; }
          for (let i = 0; i < 100 && !(f.contentWindow.Livewire && f.contentDocument.querySelector('[wire\\:id]')); i++) await sleep(200);
          await sleep(800);
          clearTimeout(timer);
          resolve(f);
        } catch (e) { clearTimeout(timer); f.remove(); reject(e); }
      };
      document.body.appendChild(f);
    });
  }
  const component = (f) => { const el = f.contentDocument.querySelector('[wire\\:id]'); return el ? f.contentWindow.Livewire.find(el.getAttribute('wire:id')) : null; };
  const text = (n) => (n ? n.textContent.replace(/[\s ]+/g, ' ').trim() : '');

  /** A page's table as rows of text, header first. Inputs and selects count by their current value; action columns are dropped. */
  function tableRows(table) {
    if (!table) return null;
    const skip = /^(actions?|save|del|delete|stock|remove|edit|pick|see|)$/i;
    const heads = [...table.querySelectorAll('thead th')].map(text);
    if (!heads.length) return null;
    const keep = heads.map((h, i) => (skip.test(h) ? -1 : i)).filter((i) => i >= 0);
    const cell = (td) => {
      const fields = [...td.querySelectorAll('input:not([type=hidden]):not([type=checkbox]),select,textarea')];
      if (fields.length) return fields.map((x) => (x.tagName === 'SELECT' ? (x.options[x.selectedIndex] ? x.options[x.selectedIndex].text : '') : x.value || x.getAttribute('value') || '')).join(' ').trim();
      return text(td);
    };
    const rows = [...table.querySelectorAll('tbody tr')].map((tr) => [...tr.children])
      .filter((tds) => tds.length >= heads.length - 1 && !/no records|no data|no drugs found|under maintenance|loading/i.test(tds.map(text).join(' ')));
    return [keep.map((i) => heads[i]), ...rows.map((tds) => keep.map((i) => (tds[i] ? cell(tds[i]) : '')))];
  }
  const findTable = (doc, re) => [...doc.querySelectorAll('table')].find((t) => re.test([...t.querySelectorAll('thead th')].map(text).join('|')));

  /** Calls a Livewire action until the counted items stop growing. */
  async function grow(f, action, count, target) {
    const c = component(f);
    let last = -1, same = 0;
    while (count() !== last || same < 2) {
      check();
      if (target && count() >= target) break;
      if (count() === last) same++; else { same = 0; last = count(); }
      try { await c.call(action); } catch (e) { break; }
      await sleep(250);
      if (target) step('Loading the patient directory… ' + count() + ' of ' + target, 5 + 15 * count() / target);
    }
  }

  /* ---------- 1. patients ---------- */
  const files = {};
  const pcols = ['Patients ID', 'Name', 'Sex', 'DOB', 'Age', 'Contact', 'Address', 'E-mail', 'Occupation', 'Company', 'Next of Kin', 'Next of Kin Contact', 'Insurance Scheme', 'Insurance#', 'Staff ID', 'Patient Type', 'SMS Alert', 'First Visit', 'Branch', 'internal_id'];

  /** A patient's folder page: their details, and how many visits it lists (none: the print page would fail). */
  const FOLDER_LABELS = [['Name', 'Name'], ['Sex', 'Sex'], ['Contact', 'Contact'], ['Next of Kin', 'Next of Kin'], ['Next of Kin Contact', 'Next of Kin Contact'],
    ['Occupation', 'Occupation'], ['Insurance', 'Insurance Scheme'], ['Age', 'Age'], ['DOB', 'DOB'], ['Address', 'Address'], ['Other Info', null],
    ['Insure #', 'Insurance#'], ['Patient ID', 'Patients ID'], ['First Visit Date', 'First Visit']];
  async function folderInfo(id) {
    const doc = await getDoc('/hms-patient-folder/' + id);
    const visits = [...doc.querySelectorAll('select option')].filter((o) => o.value.trim() !== '').length;
    const t = (doc.body.textContent || '').replace(/[\s ]+/g, ' ');
    const from = t.indexOf('PATIENT CLINIC RECORDS');
    const to = t.indexOf('PATIENT FILE ATTACHMENTS');
    const s = t.slice(from >= 0 ? from : 0, to > from ? to : t.length);
    const r = { internal_id: String(id) };
    let at = 0;
    FOLDER_LABELS.forEach(([label, col], k) => {
      const i = s.indexOf(label + ':', at);
      if (i < 0) return;
      const startV = i + label.length + 1;
      let endV = s.length;
      for (let j = k + 1; j < FOLDER_LABELS.length; j++) { const n = s.indexOf(FOLDER_LABELS[j][0] + ':', startV); if (n >= 0) { endV = n; break; } }
      if (col) r[col] = s.slice(startV, endV).trim().replace(/^N\/A$/i, '');
      at = startV;
    });
    return r['Patients ID'] ? { details: r, visits } : null;
  }

  let patients = [];
  if (mode === 'all') {
    step('Opening the patient directory…', 2);
    const dir = await frame('/patient-directory');
    const ddoc = dir.contentDocument;
    const branch = ddoc.querySelector('select[wire\\:model*="ranch"]');
    if (branch && [...branch.options].some((o) => o.value === 'all') && branch.value !== 'all') {
      await component(dir).set(branch.getAttribute('wire:model'), 'all'); await sleep(1500);
    }
    const total = parseInt(((ddoc.body.innerText.match(/Count:\s*([\d,]+)/) || [])[1] || '0').replace(/,/g, ''), 10) || 0;
    if (total > 6000) { dir.remove(); throw new Error('This clinic has ' + total.toLocaleString() + ' patients: too many for one go. Click the button again and copy it in stages (1-2000, 2001-4000, and so on, then LISTS).'); }
    const cards = () => ddoc.querySelectorAll('[onclick^="openPatientFolderWindow"]').length;
    if ([...ddoc.querySelectorAll('[wire\\:click]')].some((b) => /loadMore/.test(b.getAttribute('wire:click')))) await grow(dir, 'loadMore', cards, total || null);
    check();

    const LABELS = ['Name', 'Contact', 'Sex', 'Age', 'DOB', 'Address', 'E-mail', 'Next of Kin', 'Next of Kin Contact', 'Occupation', 'Company', 'Insurance Scheme', 'Staff ID', 'Insurance#', 'Patient Type', 'SMS Alert', 'First Visit', 'Branch'];
    ddoc.querySelectorAll('[onclick^="openPatientFolderWindow"]').forEach((a) => {
      let card = a;
      while (card && !card.querySelector('img[alt="Patient Photo"]')) card = card.parentElement;
      if (!card) return;
      const ps = [...card.querySelectorAll('p')].map(text);
      const r = { 'Patients ID': '', internal_id: (a.getAttribute('onclick').match(/\d+/) || [''])[0] };
      for (let i = 0; i < ps.length; i++) {
        if (LABELS.includes(ps[i]) && !(ps[i] in r)) { const v = ps[i + 1]; r[ps[i]] = v !== undefined && !LABELS.includes(v) && v !== 'Patients ID' ? v : ''; }
        if (ps[i] === 'Patients ID') r['Patients ID'] = ps[i + 1] || '';
      }
      patients.push(r);
    });
    dir.remove();
    if (!patients.length) throw new Error('No patients were found in the Patient\'s Directory. Is this login allowed to open it?');
    log(patients.length + ' patients' + (total && patients.length < total ? ' of ' + total + ' listed: the directory stopped loading early' : ''), total && patients.length < total);
  }

  /* ---------- 2. every patient's records ---------- */
  if (mode !== 'lists') {
    // A stage walks its range of internal numbers and reads each folder page for the details, so the full
    // directory (slow for a big clinic) is never opened. In both ways a patient with no visits is not printed:
    // iTestEMR's print page fails for them, and their details are all there is to keep.
    const ids = mode === 'range' ? Array.from({ length: range[1] - range[0] + 1 }, (_, k) => String(range[0] + k)) : patients.map((p) => p.internal_id);
    const byId = new Map(patients.map((p) => [p.internal_id, p]));
    const found = [], folders = [], failed = [];
    let done = 0, next = 0, empty = 0, novisits = 0;
    const label = mode === 'range' ? 'Copying patients ' + range[0] + '-' + range[1] + '… ' : 'Copying patient records… ';
    async function worker() {
      while (next < ids.length) {
        check();
        const k = next++, id = ids[k];
        let info = null, html = null, settled = false;
        for (let attempt = 0; attempt < 3 && !settled; attempt++) {
          try {
            if (info === null) {
              info = await folderInfo(id);
              if (!info) {
                if (mode === 'range') { empty++; settled = true; break; }
                info = { details: byId.get(id), visits: 1 };
              }
            }
            if (info.visits > 0) {
              const doc = await getDoc('/hms-print-all-patient-folder/' + id);
              doc.querySelectorAll('script,style,link,noscript,img').forEach((e) => e.remove());
              html = doc.body.innerHTML;
            } else novisits++;
            settled = true;
          } catch (e) {
            if (e === SignedOut) throw e;
            if (attempt === 2) { const p = (info && info.details) || byId.get(id) || {}; failed.push((p['Patients ID'] || '#' + id) + (p.Name ? ' (' + p.Name + ')' : '')); }
            else await sleep(1500 * (attempt + 1));
          }
        }
        if (info && info.details) {
          found[k] = mode === 'range' ? info.details : Object.assign({}, info.details, byId.get(id) || {});
          if (html !== null) folders[k] = '<section class="px" data-internal-id="' + id + '">' + html + '</section>';
        }
        done++;
        if (done % 10 === 0 || done === ids.length) step(label + done + ' of ' + ids.length, 5 + (mode === 'range' ? 90 : 80) * done / ids.length);
      }
    }
    await Promise.all([worker(), worker(), worker(), worker()]);
    patients = found.filter(Boolean);
    files['patients.csv'] = csv([pcols, ...patients.map((p) => pcols.map((c) => p[c] || ''))]);
    const copied = folders.filter(Boolean);
    const visits = copied.reduce((n, f) => n + (f.match(/Visit\s*#\s*\d+/g) || []).length, 0);
    files['patient-records.html'] = '<!doctype html><html><head><meta charset="utf-8"><title>iTestEMR patient records, ' + host + ', ' + today + '</title></head><body>' + copied.join('\n') + '</body></html>';
    if (mode === 'range') {
      const nums = patients.map((p) => +p.internal_id);
      log('Stage ' + range[0] + '-' + range[1] + ': ' + patients.length + ' patients' + (nums.length ? ' (internal numbers ' + Math.min(...nums) + ' to ' + Math.max(...nums) + ')' : '') + (empty ? '; ' + empty + ' numbers hold no patient' : ''));
      if (!patients.length) log('No patients in this range. If the stage before had patients right up to its end, try one more range; otherwise every patient is copied.', true);
    }
    log(copied.length + ' patient folders with ' + visits + ' visits' + (novisits ? '; ' + novisits + ' patients have no visits (details only)' : ''));
    if (failed.length) log(failed.length + ' patient folders would not open in iTestEMR: ' + failed.join(', ') + '. Run this stage again, or print these from iTestEMR by hand.', true);
  }

  /* ---------- 3. stock lists and staff ---------- */
  const lists = [
    ['frames.csv', 'Frames', async () => tableRows(findTable(await getDoc('/hms-frame-inventory'), /Frame Code/))],
    ['frame-stocking.csv', 'Frame stock purchases', async () => tableRows(findTable(await getDoc('/hms-frame-stocking'), /Stock Date/))],
    ['accessories.csv', 'Accessories', async () => tableRows(findTable(await getDoc('/hms-accessories'), /Accessory/))],
    ['lens-types.csv', 'Lens types', async () => tableRows(findTable(await getDoc('/hms-lens-types'), /Lens Type/))],
    ['lens-prices.csv', 'Lens prices', async () => { const f = await frame('/hms-internal-lens-prices'); try { return tableRows(findTable(f.contentDocument, /LensID/)); } finally { f.remove(); } }],
    ['lens-stocking.csv', 'Lens stock purchases', async () => tableRows(findTable(await getDoc('/hms-internal-lens-stocking'), /Reflectivity/))],
    ['drugs.csv', 'Drug list', async () => {
      const f = await frame('/hms-drug-reconciliation');
      try {
        const seen = new Map(); const c = component(f);
        let head = null;
        for (let page = 1; page <= 200; page++) {
          check();
          if (page > 1) { try { await c.call('gotoPage', page, 'page'); } catch (e) { break; } await sleep(500); }
          const rows = tableRows(findTable(f.contentDocument, /Drug Name/));
          if (!rows) break;
          head = rows[0];
          const before = seen.size;
          rows.slice(1).forEach((r) => seen.set(r.join('|'), r));
          if (seen.size === before) break;
        }
        return head ? [head, ...seen.values()] : null;
      } finally { f.remove(); }
    }],
    ['drug-stocking.csv', 'Drug stock purchases', async () => {
      const f = await frame('/hms-drug-stocking-report');
      try {
        const from = f.contentDocument.querySelector('input[type=date]');
        if (from && from.getAttribute('wire:model')) { await component(f).set(from.getAttribute('wire:model'), '2000-01-01'); await sleep(2000); }
        return tableRows(findTable(f.contentDocument, /Expiry Date/));
      } finally { f.remove(); }
    }],
    ['drug-expiry.csv', 'Drug expiry', async () => tableRows(findTable(await getDoc('/hms-expired-drugs-report'), /Notice/))],
    ['staff.csv', 'Staff', async () => {
      const f = await frame('/hms-eye-employees');
      try {
        const count = () => (f.contentDocument.body.innerText.match(/User Registration Date/g) || []).length;
        if ([...f.contentDocument.querySelectorAll('[wire\\:click]')].some((b) => /loadMore/.test(b.getAttribute('wire:click')))) await grow(f, 'loadMore', count);
        const all = f.contentDocument.body.innerText;
        const t = all.slice(Math.max(0, all.indexOf("STAFF'S DIRECTORY")));
        const blocks = t.split(/\n(?=[^\n]+\n+Branch:)/).filter((b) => /Branch:/.test(b));
        const g = (b, k, n = 0) => { const m = [...b.matchAll(new RegExp(k + ':\\s*([^\\n]*)', 'g'))]; return m[n] ? m[n][1].trim() : ''; };
        const head = ['Name', 'Branch', 'Contact', 'Email', 'Registered', 'Role', 'Department', 'Next of Kin', 'Next of Kin Contact', 'Staff ID', 'State'];
        return [head, ...blocks.map((b) => [b.split('\n')[0].trim(), g(b, 'Branch'), g(b, 'Contact'), g(b, 'Email'), g(b, 'User Registration Date'), g(b, 'Status'), g(b, 'Department'), g(b, 'Next of Kin'), g(b, 'Contact', 1), (b.match(/\n(\d+)\n+Edit/) || [])[1] || '', (b.match(/Edit\n+(\w+)/) || [])[1] || ''])];
      } finally { f.remove(); }
    }],
    // Administrator-only pages: a clinician login gets "may not open it" for these, and the import works without them.
    ['service-prices.csv', 'Services and products price list', async () => tableRows(findTable(await getDoc('/clinic-services'), /Product\/Service/))],
    ['drug-prices.csv', 'Drug prices', async () => tableRows(findTable(await getDoc('/hms-drugs-inventory'), /Selling Price/))],
    ['payments.csv', 'Payments', async () => {
      const f = await frame('/hms-cash-payment-report');
      try {
        const d = f.contentDocument;
        const put = (el, value) => { el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
        const branchSel = d.querySelector('select');
        if (branchSel && [...branchSel.options].some((o) => o.value === 'all')) put(branchSel, 'all');
        const dates = d.querySelectorAll('input[type=date]');
        if (dates.length < 2) return null;
        put(dates[0], '2000-01-01'); put(dates[1], new Date().toISOString().slice(0, 10));
        await sleep(800);
        // The page already says "No records found" before a search, so wait for the result to change, then settle.
        const before = d.body.innerText;
        const search = [...d.querySelectorAll('button')].find((b) => /search/i.test(b.textContent));
        if (search) search.click(); else await component(f).call('generateReport');
        for (let i = 0; i < 240 && !/Grand Total/i.test(d.body.innerText) && d.body.innerText === before; i++) { check(); await sleep(500); }
        for (let i = 0, last = ''; i < 60 && !/Grand Total/i.test(d.body.innerText) && d.body.innerText !== last; i++) { last = d.body.innerText; await sleep(1000); }
        const lines = d.body.innerText.split('\n').map((s) => s.trim()).filter(Boolean);
        const rows = [['Date', 'No', 'Patient', 'Mode', 'Amount', 'Taken By']];
        let day = null, sum = 0;
        for (let i = 0; i < lines.length; i++) {
          const dm = lines[i].match(/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+(\w+ \d{1,2}, \d{4})$/);
          if (dm) { const dt = new Date(dm[2] + ' 12:00'); day = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); continue; }
          const m = lines[i].match(/^(\d+)\s+(.+?)\s+—\s+(.+?)\s+—\s+([\d,]+(?:\.\d+)?)$/);
          if (m && day) { const by = (lines[i + 1] || '').match(/^by:\s*(.*)$/); const amt = parseFloat(m[4].replace(/,/g, '')); sum += amt; rows.push([day, m[1], m[2], m[3], amt.toFixed(2), by ? by[1] : '']); }
        }
        const grand = parseFloat(((d.body.innerText.match(/Grand Total:\s*₵\s*([\d,.]+)/) || [])[1] || '').replace(/,/g, ''));
        if (!isNaN(grand) && Math.abs(grand - sum) > 0.01) log('Payments: the lines add up to ' + sum.toFixed(2) + ' but iTestEMR shows a grand total of ' + grand.toFixed(2), true);
        return rows;
      } finally { f.remove(); }
    }],
    ['outstanding-balances.csv', 'Outstanding balances', async () => {
      const f = await frame('/hms-cashcredit-report');
      try {
        const d = f.contentDocument, c = component(f);
        const yearSel = [...d.querySelectorAll('select')].find((s) => [...s.attributes].some((a) => a.name.startsWith('wire:model') && a.value === 'filterYear'));
        if (!yearSel || !c) return null;
        const grab = () => { const t = findTable(d, /NetTopUp/); return t ? [...t.querySelectorAll('tbody tr')].map((r) => [...r.children].map(text)).filter((r) => r.length >= 9 && /\d{4}/.test(r[0])) : []; };
        // Count Livewire's finished updates, so an empty month need not be waited out.
        let processed = 0;
        try { f.contentWindow.Livewire.hook('message.processed', () => { processed++; }); } catch (e) { /* older Livewire: fall back to waiting */ }
        const settle = async (from) => { for (let i = 0; i < 40 && processed === from; i++) await sleep(150); await sleep(150); };
        const seen = new Map();
        const now = new Date();
        for (const year of [...yearSel.options].map((o) => o.value).filter(Boolean)) {
          await c.set('filterYear', year);
          for (let m = 1; m <= 12; m++) {
            check();
            if (Number(year) > now.getFullYear() || (Number(year) === now.getFullYear() && m > now.getMonth() + 1)) break;
            const from = processed;
            await c.set('filterMonth', String(m)); await c.call('fetchPayments');
            await settle(from);
            grab().forEach((r) => { const dt = new Date(r[0].replace(',', '')); if (dt.getMonth() + 1 === m && String(dt.getFullYear()) === year) seen.set(r[1] + '|' + r[5] + '|' + r[0], r.slice(0, 9)); });
          }
          step('Copying outstanding balances… ' + year, 98);
        }
        return [['Date', 'Invoice', 'Patient ID', 'Patient', 'Contact', 'Item', 'Net TopUp', 'Amount Paid', 'Balance'], ...seen.values()];
      } finally { f.remove(); }
    }],
    ['spectacle-orders.csv', 'Spectacle orders', async () => {
      const f = await frame('/eye/specs-search');
      try {
        const d = f.contentDocument;
        const total = parseInt((d.body.innerText.match(/Results:\s*(\d+)/) || [])[1] || '0', 10);
        const count = () => new Set([...d.querySelectorAll('[wire\\:click^="editPrescription("]')].map((b) => b.getAttribute('wire:click'))).size;
        if ([...d.querySelectorAll('[wire\\:click]')].some((b) => /loadMore/.test(b.getAttribute('wire:click')))) await grow(f, 'loadMore', count, total || null);
        const cards = [];
        d.querySelectorAll('[wire\\:click^="editPrescription("]').forEach((b) => {
          let card = b; while (card.parentElement && !/Orders ID/.test(card.textContent)) card = card.parentElement;
          while (card.parentElement && (card.parentElement.textContent.match(/Orders ID/g) || []).length === 1) card = card.parentElement;
          if (!cards.includes(card)) cards.push(card);
        });
        const rows = [['Order ID', 'Date', 'Patient ID', 'Patient', 'Contact', 'RE Rx', 'LE Rx', 'Lens', 'Add OD', 'Add OS', 'PD', 'Frame', 'Frame Price', 'Lens Price', 'Total', 'Ordered', 'Orders In', 'Received', 'Remarks', 'Staff']];
        for (const card of cards) {
          const L = card.innerText.split('\n').map((s) => s.trim()).filter(Boolean);
          const between = (a, b) => { const x = L.indexOf(a), y = L.indexOf(b); return x >= 0 && y > x ? L.slice(x + 1, y).join(' ').replace(/\s+/g, ' ').trim() : ''; };
          const val = (re) => { const l = L.find((s) => re.test(s)); return l ? l.replace(re, '').trim() : ''; };
          const after = (label) => { const x = L.indexOf(label); return x >= 0 ? (L[x + 1] || '') : ''; };
          const framesL = L.filter((s) => /^Frame:/.test(s));
          rows.push([after('Orders ID'), L[0], after('Patients ID'), L[1], L[2], between('RE:', 'LE:'), between('LE:', 'Specifications:'), between('Specifications:', 'Patients ID'),
            val(/^ADD \(OD\):/), val(/^ADD \(OS\):/), val(/^PD:/), (framesL[0] || '').replace(/^Frame:/, '').trim(), (framesL[1] || '').replace(/^Frame:/, '').trim(), val(/^Lens:/), val(/^Total:/),
            after('Ordered'), after('Orders-In'), after('Received'), after('Remarks'), after('Staff:')]);
        }
        if (total && cards.length < total) log('Spectacle orders: ' + cards.length + ' of ' + total + ' loaded', true);
        return rows;
      } finally { f.remove(); }
    }],
  ];
  for (let i = 0; mode !== 'range' && i < lists.length; i++) {
    check();
    const [name, label, read] = lists[i];
    step('Copying ' + label.toLowerCase() + '…', 86 + 12 * i / lists.length);
    try {
      const rows = await read();
      if (rows && rows.length > 1) { files[name] = csv(rows); log(label + ': ' + (rows.length - 1)); }
      else log(label + ': none found', true);
    } catch (e) {
      if (e === SignedOut || stopped) throw e;
      log(label + ': ' + (e.status === 403 ? 'this login may not open it' : 'could not be read (' + e.message + ')'), true);
    }
  }

  /* ---------- 4. the download ---------- */
  check();
  report.unshift('iTestEMR capture of ' + host + ' on ' + new Date().toString(), 'Part: ' + (mode === 'range' ? 'patients ' + range[0] + '-' + range[1] : mode === 'lists' ? 'lists only' : 'everything'), 'Signed in as: ' + (text(document.querySelector('.user-name-text') && document.querySelector('.user-name-text').parentElement) || 'not shown on this page'), '');
  if (/Role:\s*clinician/i.test(report[1])) report.splice(2, 0, 'This login is a clinician: an administrator login may see more (prices, payments).');
  if (mode !== 'range' && (!files['payments.csv'] || !files['outstanding-balances.csv'])) report.push('', 'Payments and balances were not captured: they need an administrator login.');
  files['capture-report.txt'] = report.join('\r\n');
  step('Making the .zip…', 99);
  const blob = zip(files);
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'itestemr-' + host.split('.')[0] + (mode === 'range' ? '-patients-' + range[0] + '-' + range[1] : mode === 'lists' ? '-lists' : '') + '-' + today + '.zip';
  document.body.appendChild(a); a.click(); a.remove();
  step('Done: ' + a.download + ' (' + (blob.size / 1048576).toFixed(1) + ' MB) is downloading.', 100);
  $('[data-stop]').textContent = 'Close';
  $('[data-stop]').onclick = () => { panel.remove(); };

  /* ---------- helpers: CSV and a plain .zip ---------- */
  function csv(rows) {
    const q = (v) => { v = String(v == null ? '' : v); return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    return '﻿' + rows.map((r) => r.map(q).join(',')).join('\r\n');
  }
  function zip(entries) {
    const enc = new TextEncoder();
    const table = new Uint32Array(256).map((_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
    const crc32 = (b) => { let c = 0xffffffff; for (let i = 0; i < b.length; i++) c = table[(c ^ b[i]) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
    const d = new Date(), time = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1), date = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
    const parts = [], central = [];
    let offset = 0;
    for (const [name, content] of Object.entries(entries)) {
      const nameBytes = enc.encode(name), data = enc.encode(content), crc = crc32(data);
      const head = new DataView(new ArrayBuffer(30));
      [[0, 0x04034b50, 4], [4, 20, 2], [6, 0x0800, 2], [8, 0, 2], [10, time, 2], [12, date, 2], [14, crc, 4], [18, data.length, 4], [22, data.length, 4], [26, nameBytes.length, 2], [28, 0, 2]]
        .forEach(([at, v, n]) => (n === 4 ? head.setUint32(at, v, true) : head.setUint16(at, v, true)));
      const dirEntry = new DataView(new ArrayBuffer(46));
      [[0, 0x02014b50, 4], [4, 20, 2], [6, 20, 2], [8, 0x0800, 2], [10, 0, 2], [12, time, 2], [14, date, 2], [16, crc, 4], [20, data.length, 4], [24, data.length, 4], [28, nameBytes.length, 2], [30, 0, 2], [32, 0, 2], [34, 0, 2], [36, 0, 2], [38, 0, 4], [42, offset, 4]]
        .forEach(([at, v, n]) => (n === 4 ? dirEntry.setUint32(at, v, true) : dirEntry.setUint16(at, v, true)));
      parts.push(head, nameBytes, data);
      central.push(dirEntry, nameBytes);
      offset += 30 + nameBytes.length + data.length;
    }
    const size = central.reduce((n, p) => n + p.byteLength, 0);
    const end = new DataView(new ArrayBuffer(22));
    [[0, 0x06054b50, 4], [4, 0, 2], [6, 0, 2], [8, Object.keys(entries).length, 2], [10, Object.keys(entries).length, 2], [12, size, 4], [16, offset, 4], [20, 0, 2]]
      .forEach(([at, v, n]) => (n === 4 ? end.setUint32(at, v, true) : end.setUint16(at, v, true)));
    return new Blob([...parts, ...central, end], { type: 'application/zip' });
  }
})().catch((e) => {
  window.__nexusCapture = false;
  const box = document.querySelector('[data-step]');
  if (box) { box.textContent = e.message; box.style.color = '#fca5a5'; } else alert(e.message);
}).finally(() => { window.__nexusCapture = false; });
