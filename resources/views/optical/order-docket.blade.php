<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $orders->count() === 1 ? 'Optical Docket '.$orders->first()->order_id : 'Optical Dockets ('.$orders->count().')' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #142238; font: 14px/1.5 system-ui, sans-serif; }
        /* An A5 sheet (148 x 210 mm): shown at that width on screen, printed on A5. */
        main { width: 148mm; max-width: 100%; margin: 16px auto; padding: 10mm; background: white; border: 1px solid #dbe4ef; border-radius: 8px; font-size: 12px; }
        header, .row { display: flex; justify-content: space-between; gap: 20px; align-items: start; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        h2 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; margin: 12px 0 5px; border-bottom: 1px solid #dbe4ef; padding-bottom: 3px; }
        p { margin: 3px 0; }
        .muted { color: #64748b; }
        .badge { display: inline-block; padding: 4px 9px; background: #e2f7f6; color: #0f766e; border-radius: 6px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe4ef; text-align: left; padding: 3px 5px; }
        th { background: #f8fafc; }
        .right { text-align: right; }
        .actions { width: 148mm; max-width: 100%; margin: 16px auto 0; text-align: right; }
        button { border: 0; border-radius: 7px; background: #0f766e; color: white; padding: 9px 16px; cursor: pointer; }
        /* No page margin: browsers then leave out their own date, title and address lines.
           The sheet keeps its own 8 mm margin inside. */
        @page { size: A5 portrait; margin: 0; }
        @media print {
            html, body { width: 148mm; background: #fff !important; }
            main { width: 148mm; min-height: 0; margin: 0; max-width: none; border: 0; border-radius: 0; padding: 8mm; color: #000; }
            .actions { display: none; }
            /* Plain black on white: no shaded cells, badges or grey text. */
            th, .badge { background: none !important; }
            .badge { padding: 0; color: #000; font-weight: 700; }
            .muted { color: #000; }
            th, td, h2 { border-color: #000 !important; }
            h2, table, tr { break-inside: avoid; }
            /* One order per A5 page. */
            main + main { break-before: page; }
        }
    </style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">{{ $orders->count() === 1 ? 'Print docket (A5)' : 'Print '.$orders->count().' dockets (A5, one per page)' }}</button></div>
{{-- Opened with ?print=1 (the Print buttons): print straight away. --}}
@if(request()->boolean('print'))<script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>@endif
@foreach($orders as $order)
    @include('optical.partials.docket-sheet', ['order' => $order])
@endforeach
</body>
</html>
