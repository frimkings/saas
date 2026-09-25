{{-- Shared styles for optical order lists and the order side panel. --}}
@once
<style>
        .oo-chips{display:flex;flex-wrap:wrap;gap:6px}
        .oo-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--clinic-line);background:#fff;color:var(--clinic-muted);border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer}
        .oo-chip:hover{border-color:var(--clinic-accent);color:var(--clinic-ink)}
        .oo-chip.active{background:var(--clinic-accent);border-color:var(--clinic-accent);color:#fff}
        .oo-chip b{font-size:10px;background:rgb(0 0 0 / .07);border-radius:999px;padding:1px 7px}.oo-chip.active b{background:rgb(255 255 255 / .22)}
        .oo-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--clinic-line);background:#fafcfc}
        .oo-table td,.oo-table th{padding:11px 14px;border-bottom:1px solid var(--clinic-line);vertical-align:middle;text-align:left;font-size:12.5px}
        .oo-table th{background:#f6f9fa;color:var(--clinic-muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
        .oo-table tbody tr{cursor:pointer}.oo-table tbody tr:hover{background:#f6fafa}.oo-table tr.oo-active{background:#e8f4f3}
        .oo-num{text-align:right!important;font-variant-numeric:tabular-nums;white-space:nowrap}
        .oo-id{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;color:#0f5f63;white-space:nowrap}
        .oo-sub{display:block;color:var(--clinic-muted);font-size:11px;margin-top:2px}
        .oo-badge{display:inline-flex;align-items:center;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;border:1px solid transparent;white-space:nowrap}
        .oo-b-purple{background:#f3e8ff;color:#6b21a8;border-color:#e9d5ff}.oo-b-amber{background:#fef3c7;color:#92400e;border-color:#fde68a}
        .oo-b-green{background:#d1fae5;color:#065f46;border-color:#a7f3d0}.oo-b-red{background:#fee2e2;color:#991b1b;border-color:#fecaca}
        .oo-b-teal{background:#e6f6f5;color:#0f5f63;border-color:#bfe5e2}.oo-b-grey{background:#f1f5f9;color:#475569;border-color:#e2e8f0}
        .oo-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;border-radius:7px;padding:6px 11px;font-size:12px;font-weight:700;border:1px solid var(--clinic-line);background:#fff;color:var(--clinic-ink);cursor:pointer;text-decoration:none;white-space:nowrap}
        .oo-btn:hover{border-color:#9fb9bd;background:#f6fafa}
        .oo-btn.primary{background:var(--clinic-accent);border-color:var(--clinic-accent);color:#fff}.oo-btn.primary:hover{background:#066a6e}
        .oo-btn.wa{background:#ecfdf5;border-color:#86efac;color:#166534}.oo-btn.danger{background:#fff1f2;border-color:#fecdd3;color:#b91c1c}.oo-btn.danger:hover{background:#ffe4e6}
        .oo-btn.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
        .oo-btn:disabled{opacity:.5;cursor:not-allowed}
        .oo-filters{display:flex;flex-wrap:wrap;gap:10px 12px;align-items:flex-end;padding:12px 16px;border-bottom:1px solid var(--clinic-line)}
        .oo-f{display:flex;flex-direction:column;gap:4px;width:160px}.oo-f span{font-size:11px;font-weight:700;color:var(--clinic-muted)}.oo-f .ui-input{padding:7px 9px}
        .oo-presets{display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-left:auto;padding-bottom:4px}
        .oo-link{background:none;border:0;color:var(--clinic-accent);font-size:12px;font-weight:700;cursor:pointer;padding:4px 6px;border-radius:6px}.oo-link:hover{background:#e8f4f3}
        .oo-row-actions{display:grid;grid-template-columns:36px 128px 64px;gap:6px;justify-content:end;align-items:center}
        .oo-row-actions>*{height:32px;box-sizing:border-box}.oo-row-actions .oo-slot{display:block}
        .oo-btn.wa-icon{width:36px;padding:0;font-size:16px}
        .oo-table{table-layout:fixed;min-width:1020px}
        .oo-table col.c-order{width:140px}.oo-table col.c-cust{width:165px}.oo-table col.c-work{width:auto}.oo-table col.c-amt{width:130px}.oo-table col.c-pick{width:105px}.oo-table col.c-status{width:120px}.oo-table col.c-act{width:250px}
        .oo-table td{overflow-wrap:anywhere}
        .oo-overlay{position:fixed;inset:0;z-index:1040;background:rgb(15 23 42 / .45)}
        .oo-drawer{position:fixed;top:0;right:0;z-index:1041;height:100dvh;width:min(620px,100vw);background:#fff;display:flex;flex-direction:column;box-shadow:-16px 0 40px rgb(15 23 42 / .2);animation:ooSlide .18s ease-out;outline:none}
        @keyframes ooSlide{from{transform:translateX(24px);opacity:.4}to{transform:none;opacity:1}}
        @media(prefers-reduced-motion:reduce){.oo-drawer{animation:none}}
        .oo-drawer-head{padding:18px 20px;border-bottom:1px solid var(--clinic-line);display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
        .oo-drawer-head h2{margin:0 0 6px;font-size:18px;font-weight:800}
        .oo-drawer-body{flex:1;overflow-y:auto;overflow-x:hidden;padding:18px 20px;display:grid;grid-template-columns:minmax(0,1fr);gap:18px;align-content:start}.oo-drawer-body>*{min-width:0}
        .oo-drawer-foot{border-top:1px solid var(--clinic-line);padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;background:#fafcfc}
        .oo-x{border:0;background:none;font-size:22px;line-height:1;color:var(--clinic-muted);cursor:pointer;padding:2px 6px;border-radius:6px}.oo-x:hover{background:#f1f5f9}
        .oo-steps{display:flex;list-style:none;margin:0;padding:0;min-width:0}
        .oo-steps li{flex:1;text-align:center;font-size:11px;color:var(--clinic-muted);position:relative;padding-top:24px}
        .oo-steps li::before{content:'';position:absolute;top:6px;left:50%;transform:translateX(-50%);width:14px;height:14px;border-radius:50%;background:#fff;border:2px solid #cbd5e1;z-index:1}
        .oo-steps li::after{content:'';position:absolute;top:12px;left:-50%;width:100%;height:2px;background:#cbd5e1}.oo-steps li:first-child::after{display:none}
        .oo-steps li.done::before{background:var(--clinic-accent);border-color:var(--clinic-accent)}.oo-steps li.done::after,.oo-steps li.current::after{background:var(--clinic-accent)}
        .oo-steps li.current{color:var(--clinic-ink);font-weight:700}.oo-steps li.current::before{border-color:var(--clinic-accent);box-shadow:0 0 0 4px #d7efed}
        .oo-money{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
        .oo-money div{border:1px solid var(--clinic-line);border-radius:10px;padding:10px 12px}
        .oo-money span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.3px;font-weight:700;color:var(--clinic-muted)}
        .oo-money b{font-size:16px;font-variant-numeric:tabular-nums}
        .oo-section h3{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--clinic-muted);font-weight:800}
        .oo-dl{display:grid;grid-template-columns:130px minmax(0,1fr);gap:6px 12px;margin:0;font-size:13px}.oo-dl dt{color:var(--clinic-muted)}.oo-dl dd{margin:0;min-width:0;overflow-wrap:anywhere}
        .oo-lines{width:100%;table-layout:fixed;border-collapse:collapse;font-size:12.5px}.oo-lines td{overflow-wrap:anywhere}.oo-lines td,.oo-lines th{padding:7px 8px;border-bottom:1px solid var(--clinic-line);text-align:left}.oo-lines th{font-size:11px;color:var(--clinic-muted)}
        .oo-note{border-radius:9px;padding:10px 12px;font-size:12.5px}.oo-note.amber{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.oo-note.red{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
        .oo-rx th,.oo-rx td{text-align:center!important}.oo-rx tbody th{text-align:left!important;color:var(--clinic-ink)}
        .oo-b-blue{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}
        /* With the panel open, print only the panel (the bench ticket). */
        @media print{body:has(.oo-drawer) *{visibility:hidden}.oo-drawer,.oo-drawer *{visibility:visible}.oo-drawer{position:absolute;inset:0 auto auto 0;width:100%;height:auto;box-shadow:none;animation:none}.oo-drawer-body{overflow:visible}.oo-drawer-foot,.oo-x,.oo-drawer .oo-btn{display:none!important}}
        .oo-modal-wrap{position:fixed;inset:0;z-index:1060;background:rgb(15 23 42 / .55);display:flex;align-items:center;justify-content:center;padding:16px}
        @media(max-width:700px){.oo-f{width:calc(50% - 6px)}.oo-presets{margin-left:0}.oo-toolbar{grid-template-columns:1fr}.oo-money{grid-template-columns:repeat(2,minmax(0,1fr))}.oo-dl{grid-template-columns:1fr}}
</style>
@endonce
