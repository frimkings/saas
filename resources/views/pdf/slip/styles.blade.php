{{-- A5 slips (spectacle and drug prescriptions). $paper: 'a5' or 'a4_two' (two copies side by side on A4 landscape). --}}
<style>
    @if(($paper ?? 'a5') === 'a4_two')
    @page { size: A4 landscape; margin: 7mm 6mm 7mm 6mm; }
    @else
    @page { size: A5 portrait; margin: 9mm 9mm 12mm 9mm; }
    @endif
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.8pt; line-height: 1.35; color: #1f2933; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    .copies > tbody > tr > td { width: 50%; padding: 0 6mm; }
    .copies > tbody > tr > td.first { border-right: 1px dashed #94a3b8; padding-left: 0; }
    .copies > tbody > tr > td.second { padding-right: 0; }
    .copy-label { font-size: 6.8pt; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; text-align: right; margin-bottom: 2px; }

    .s-head { border-bottom: 2px solid #0f766e; padding-bottom: 4px; margin-bottom: 5px; }
    .s-logo { max-height: 30px; max-width: 80px; margin-right: 6px; }
    .s-clinic { font-size: 10.5pt; font-weight: bold; color: #0f3d3a; }
    .s-meta { font-size: 7pt; color: #52606d; }
    .s-title { font-size: 11pt; font-weight: bold; color: #0f766e; margin: 4px 0 4px; }
    .s-title .rx { font-size: 13pt; font-style: italic; font-weight: bold; color: #0f3d3a; margin-right: 5px; }
    .s-patient td { font-size: 8pt; padding: 1px 0; }
    .k { color: #64748b; white-space: nowrap; padding-right: 4px; }
    .b { font-weight: bold; }

    .s-section { font-size: 7.2pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; color: #0f766e; border-bottom: 1px solid #cbd5e1; padding-bottom: 1px; margin: 7px 0 3px; }
    .rxgrid th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 3px 3px; font-size: 7.6pt; text-align: center; color: #334155; }
    .rxgrid td { border: 1px solid #cbd5e1; padding: 5px 3px; font-size: 10.5pt; text-align: center; font-weight: bold; }
    .rxgrid td.eye { font-size: 8pt; text-align: left; white-space: nowrap; }
    .rxgrid td.legacy { font-weight: normal; font-size: 9pt; text-align: left; }
    .chips span { display: inline-block; margin: 0 8px 2px 0; font-size: 8pt; white-space: nowrap; }
    .box { display: inline-block; width: 8px; height: 8px; border: 1px solid #475569; margin-right: 3px; text-align: center; line-height: 7px; font-size: 7px; }

    .drugs td { border-bottom: 1px solid #e2e8f0; padding: 4px 2px; }
    .drugs .n { width: 5%; color: #64748b; }
    .drug { font-size: 9.6pt; font-weight: bold; }
    .eye-big { font-size: 9pt; font-weight: bold; color: #0f3d3a; border: 1px solid #0f766e; border-radius: 3px; padding: 1px 4px; white-space: nowrap; }
    .how { font-size: 8pt; color: #334155; }
    .tag { font-size: 6.8pt; font-weight: bold; color: #b45309; text-transform: uppercase; }
    .muted { color: #64748b; }
    .small { font-size: 7.4pt; }

    .s-sign td { padding-top: 16px; font-size: 7.8pt; width: 50%; }
    .s-sign .line { border-top: 1px solid #475569; padding-top: 2px; width: 85%; }
    .s-foot { border-top: 1px solid #cbd5e1; margin-top: 6px; padding-top: 2px; font-size: 6.8pt; color: #64748b; }
</style>
