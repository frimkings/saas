@props(['active', 'title', 'subtitle' => null])
{{-- Platform page frame: shared sidebar, page header and the common card/form/table styles. --}}
<div class="pp-shell">
<style>
body{margin:0}
.pp-shell{--bg:#07101f;--panel:#101b2e;--line:#22314a;--muted:#8fa1bb;--label:#9fc2ef;--blue:#38bdf8;--green:#34d399;--amber:#fbbf24;--red:#fb7185;min-height:100vh;background:var(--bg);color:#e8eef8;display:flex;font:13px Arial}
.pp-main{flex:1;min-width:0;padding:24px 28px;max-width:1300px;box-sizing:border-box}
.pp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
.pp-head h1{margin:0 0 5px;font-size:24px}.pp-head p{margin:0;color:var(--muted);max-width:720px;line-height:1.5}
.pp-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}
.pp-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}
.pp-card-head h2{margin:0 0 4px;font-size:15px}.pp-card-head p{margin:0;color:var(--muted);font-size:12px;line-height:1.5}
.pp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.pp-stat{background:#0b1526;border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.pp-stat span{display:block;font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--label)}
.pp-stat b{display:block;font-size:24px;margin:8px 0 4px}.pp-stat small{color:var(--muted);font-size:11px}
.pp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:7px;padding:9px 14px;font-weight:700;background:#1688c4;color:#fff;cursor:pointer;text-decoration:none;white-space:nowrap;font:inherit;font-weight:700}
.pp-btn:hover{background:#1a9bdc}.pp-btn.alt{background:#26354b}.pp-btn.alt:hover{background:#314560}.pp-btn.danger{background:#9f1239}.pp-btn.danger:hover{background:#be123c}
.pp-btn.sm{padding:6px 10px;font-size:12px}.pp-btn:disabled{opacity:.5;cursor:not-allowed}
.pp-btn:focus-visible,.pp-field input:focus,.pp-field select:focus,.pp-table input:focus,.pp-table select:focus{outline:2px solid var(--blue);outline-offset:1px}
.pp-form{display:grid;gap:12px}.pp-row{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));align-items:start}
.pp-field{display:flex;flex-direction:column;gap:5px;min-width:0}.pp-field>span{font-size:11px;font-weight:700;color:var(--label)}
.pp-field input,.pp-field select,.pp-table input,.pp-table select{box-sizing:border-box;width:100%;background:#050d1c;border:1px solid #273750;color:#edf5ff;border-radius:8px;padding:9px 11px;font:inherit}
.pp-hint{color:var(--muted);font-size:11px;line-height:1.45}.pp-err{color:var(--red);font-size:11px}
.pp-foot{display:flex;justify-content:flex-end;gap:8px;align-items:center}
.pp-ok{background:#10382f;color:#86efca;border:1px solid #1f6b57;padding:10px 12px;border-radius:9px;margin-bottom:14px}
.pp-warn{background:#3a2d0c;color:#fde68a;border:1px solid #7c5a11;padding:10px 12px;border-radius:9px;margin-bottom:14px}
.pp-table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:9px}
.pp-table{width:100%;border-collapse:collapse}.pp-table th,.pp-table td{padding:11px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
.pp-table th{background:#0b1526;color:var(--label);font-size:11px;text-transform:uppercase;letter-spacing:.3px}.pp-table tr:last-child td{border-bottom:0}
.pp-table td small{display:block;color:var(--muted);margin-top:2px}.pp-empty{text-align:center;color:var(--muted);padding:26px!important}
.pp-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.pp-badge{display:inline-block;padding:3px 7px;border-radius:5px;font-size:10px;font-weight:800;border:1px solid #175e5a;background:#0d3535;color:var(--green)}
.pp-badge.amber{border-color:#7c5a11;background:#3a2d0c;color:var(--amber)}.pp-badge.red{border-color:#7b2740;background:#471c2c;color:var(--red)}.pp-badge.grey{border-color:#334560;background:#1b2940;color:#aebbd0}
.pp-tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:16px;overflow-x:auto}
.pp-tabs button{background:none;border:0;border-bottom:2px solid transparent;color:#aebbd0;padding:11px 14px;font:inherit;font-weight:700;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:6px}
.pp-tabs button.active{color:var(--blue);border-bottom-color:var(--blue)}
.pass{color:var(--green)}.warn{color:var(--amber)}.fail{color:var(--red)}
@media(max-width:900px){.pp-shell{display:block}.pp-main{padding:18px 16px}.pp-stats{grid-template-columns:1fr}}
@media(max-width:600px){.pp-head{flex-direction:column}}
</style>
<x-platform.sidebar :active="$active" />
<main class="pp-main">
    <header class="pp-head">
        <div><h1>{{ $title }}</h1>@if($subtitle)<p>{{ $subtitle }}</p>@endif</div>
        @isset($actions)<div class="pp-actions">{{ $actions }}</div>@endisset
    </header>
    {{ $slot }}
</main>
</div>
