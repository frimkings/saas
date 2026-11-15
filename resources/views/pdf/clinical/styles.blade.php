{{--
    Shared look of the clinical documents (visit summary, consultation report, medical record), for
    dompdf and for printing from the browser. dompdf has no flex or grid: columns are tables.
    $screen: true when the page is shown in a browser (adds a page margin and the print bar).
--}}
<style>
    @page { size: A4; margin: 12mm 12mm 16mm 12mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.6pt; line-height: 1.35; color: #1f2933; margin: 0; }
    @if($screen ?? false)
    body { max-width: 190mm; margin: 10mm auto; }
    .print-bar { text-align: right; margin-bottom: 8px; }
    .print-bar button { background: #0f766e; color: #fff; border: 0; border-radius: 6px; padding: 7px 14px; font-size: 10pt; cursor: pointer; }
    @media print { .print-bar { display: none; } body { margin: 0; max-width: none; } }
    @endif
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    /* Header band: clinic on the left, patient on the right. */
    .doc-head { border-bottom: 2px solid #0f766e; padding-bottom: 6px; margin-bottom: 8px; }
    .doc-head td { padding: 0; }
    .doc-logo { max-height: 40px; max-width: 110px; margin-right: 8px; }
    .clinic-name { font-size: 12pt; font-weight: bold; color: #0f3d3a; }
    .clinic-meta { font-size: 7.4pt; color: #52606d; }
    .doc-title { font-size: 10pt; font-weight: bold; color: #0f766e; text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }
    .pt-box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px 6px; }
    .pt-box td { padding: 1px 4px 1px 0; font-size: 8pt; }
    .pt-name { font-size: 10pt; font-weight: bold; }
    .k { color: #64748b; white-space: nowrap; }

    /* Sections */
    .section-title { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; color: #0f766e; border-bottom: 1px solid #cbd5e1; padding-bottom: 2px; margin: 8px 0 4px; }
    .alerts td { border: 1px solid #e2e8f0; padding: 4px 6px; width: 25%; }
    .alerts .lbl { font-size: 7pt; color: #64748b; text-transform: uppercase; }
    .alerts .val { font-weight: bold; }
    .warn { color: #b91c1c; font-weight: bold; }

    /* Compact tables */
    .grid th { background: #f1f5f9; border: 1px solid #d5dde6; padding: 2px 4px; font-size: 7.6pt; text-align: left; color: #334155; }
    .grid td { border: 1px solid #e2e8f0; padding: 2px 4px; font-size: 8pt; }
    .grid .rowhead { color: #475569; white-space: nowrap; }

    /* Visit card */
    .card { border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 7px; page-break-inside: avoid; }
    .card-head { background: #f1f5f9; border-bottom: 1px solid #cbd5e1; padding: 3px 7px; }
    .card-head td { font-size: 8.4pt; }
    .card-body > table > tbody > tr > td { padding: 5px 7px; }
    .card-left { width: 52%; border-right: 1px solid #e2e8f0; }
    .f { margin-bottom: 4px; }
    .f .lbl { font-size: 7pt; color: #64748b; text-transform: uppercase; letter-spacing: .3px; display: block; }
    .dx { font-weight: bold; color: #0f3d3a; }
    .muted { color: #64748b; }
    .small { font-size: 7.4pt; }
    .normal-line { font-size: 7.6pt; color: #475569; margin-top: 3px; }
    .addendum { border-left: 2px solid #0f766e; padding-left: 5px; margin-top: 3px; }

    /* Signature and footer */
    .sign td { padding-top: 22px; width: 50%; font-size: 8pt; }
    .sign .line { border-top: 1px solid #475569; width: 75%; padding-top: 2px; }
    .doc-foot { position: fixed; bottom: -10mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #cbd5e1; font-size: 7pt; color: #64748b; padding-top: 2px; }
    @if($screen ?? false)
    .doc-foot { position: static; margin-top: 10px; height: auto; }
    @endif
</style>
