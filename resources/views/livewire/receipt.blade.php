<div id="receipt-content" style="display:none; width: 80mm; font-family: monospace; font-size: 12px;">

    <div style="text-align:center;">
        <h4>My Shop Name</h4>
        <p>Address Line 1<br>City, Country</p>
        <hr>
        <p>Date: {{ $sale->created_at->format('Y-m-d H:i') }}</p>
        <p>Receipt #: {{ $sale->id }}</p>
        <hr>
    </div>

    <table style="width:100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="text-align:left;">Item</th>
                <th style="text-align:center;">Qty</th>
                <th style="text-align:right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td>{{ \Illuminate\Support\Str::limit($item->display_product_name, 15) }}</td>
                    <td style="text-align:center;">{{ $item->quantity }}</td>
                    <td style="text-align:right;">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <hr>
    <table style="width:100%;">
        <tr>
            <td style="text-align:left;">Total:</td>
            <td style="text-ali
