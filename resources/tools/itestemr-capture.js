/*
 * Nexus App: iTestEMR capture.
 * Run while signed in to the clinic's iTestEMR (any page of it). It reads, with that login, the patient
 * directory, every patient's "Print All Records" page, the stock lists and the staff list, and downloads
 * one .zip that Legacy Imports -> From other software -> iTestEMR takes as it is. It only reads pages:
 * nothing in iTestEMR is changed. The platform turns this file into the bookmark button.
 */
(async () => {
  if (window.__nexusCapture) { alert('The Nexus capture is already running in this tab.'); return; }
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
  step('Opening the patient directory…', 2);
  const dir = await frame('/patient-directory');
  const ddoc = dir.contentDocument;
  const branch = ddoc.querySelector('select[wire\\:model*="ranch"]');
  if (branch && [...branch.options].some((o) => o.value === 'all') && branch.value !== 'all') {
    await component(dir).set(branch.getAttribute('wire:model'), 'all'); await sleep(1500);
  }
  const total = parseInt(((ddoc.body.innerText.match(/Count:\s*([\d,]+)/) || [])[1] || '0').replace(/,/g, ''), 10) || 0;
  const cards = () => ddoc.querySelectorAll('[onclick^="openPatientFolderWindow"]').length;
  if ([...ddoc.querySelectorAll('[wire\\:click]')].some((b) => /loadMore/.test(b.getAttribute('wire:click')))) await grow(dir, 'loadMore', cards, total || null);
  check();

  const LABELS = ['Name', 'Contact', 'Sex', 'Age', 'DOB', 'Address', 'E-mail', 'Next of Kin', 'Next of Kin Contact', 'Occupation', 'Company', 'Insurance Scheme', 'Staff ID', 'Insurance#', 'Patient Type', 'SMS Alert', 'First Visit', 'Branch'];
  const patients = [];
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
  const pcols = ['Patients ID', 'Name', 'Sex', 'DOB', 'Age', 'Contact', 'Address', 'E-mail', 'Occupation', 'Company', 'Next of Kin', 'Next of Kin Contact', 'Insurance Scheme', 'Insurance#', 'Staff ID', 'Patient Type', 'SMS Alert', 'First Visit', 'Branch', 'internal_id'];
  files['patients.csv'] = csv([pcols, ...patients.map((p) => pcols.map((c) => p[c] || ''))]);
  log(patients.length + ' patients' + (total && patients.length < total ? ' of ' + total + ' listed: the directory stopped loading early' : ''), total && patients.length < total);

  /* ---------- 2. every patient's records ---------- */
  const folders = new Array(patients.length);
  const failed = [];
  let done = 0, next = 0;
  async function worker() {
    while (next < patients.length) {
      check();
      const i = next++, p = patients[i];
      let html = null;
      for (let attempt = 0; attempt < 2 && html === null; attempt++) {
        try {
          const doc = await getDoc('/hms-print-all-patient-folder/' + p.internal_id);
          doc.querySelectorAll('script,style,link,noscript,img').forEach((e) => e.remove());
          html = doc.body.innerHTML;
        } catch (e) {
          if (e === SignedOut) throw e;
          if (attempt) failed.push(p['Patients ID'] + ' (' + p.Name + ')'); else await sleep(1000);
        }
      }
      if (html !== null) folders[i] = '<section class="px" data-internal-id="' + p.internal_id + '">' + html + '</section>';
      done++;
      step('Copying patient records… ' + done + ' of ' + patients.length, 20 + 65 * done / patients.length);
    }
  }
  await Promise.all([worker(), worker(), worker(), worker()]);
  const copied = folders.filter(Boolean);
  const visits = copied.reduce((n, f) => n + (f.match(/Visit\s*#\s*\d+/g) || []).length, 0);
  files['patient-records.html'] = '<!doctype html><html><head><meta charset="utf-8"><title>iTestEMR patient records, ' + host + ', ' + today + '</title></head><body>' + copied.join('\n') + '</body></html>';
  log(copied.length + ' patient folders with ' + visits + ' visits');
  if (failed.length) log(failed.length + ' patient folders would not open in iTestEMR: ' + failed.join(', ') + '. Print these from iTestEMR by hand.', true);

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
  ];
  for (let i = 0; i < lists.length; i++) {
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
  report.unshift('iTestEMR capture of ' + host + ' on ' + new Date().toString(), 'Signed in as: ' + (text(document.querySelector('.user-name-text') && document.querySelector('.user-name-text').parentElement) || 'not shown on this page'), '');
  if (/Role:\s*clinician/i.test(report[1])) report.splice(2, 0, 'This login is a clinician: an administrator login may see more (prices, payments).');
  report.push('', 'Payments, invoices and spectacle orders are not part of this capture.');
  files['capture-report.txt'] = report.join('\r\n');
  step('Making the .zip…', 99);
  const blob = zip(files);
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'itestemr-' + host.split('.')[0] + '-' + today + '.zip';
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
