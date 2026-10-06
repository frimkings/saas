<div class="clinic-ui ui-page">
    <style>
        .oo-card{background:#fff;border:1px solid #e3e8ef;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
        .oo-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #eef1f5}
        .oo-bar input[type=text]{flex:1;min-width:200px;font-size:13px;padding:6px 9px;border:1px solid #cbd5e1;border-radius:6px}
        .oo-bar button{font-size:13px;padding:6px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;color:#334155;cursor:pointer}
        .oo-bar .oo-go{background:#16a34a;border-color:#16a34a;color:#fff;font-weight:600}
        .oo-bar .oo-go:disabled{opacity:.5;cursor:not-allowed}
        .oo-table{width:100%;border-collapse:collapse;font-size:13px}
        .oo-table th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b;text-align:left;padding:8px 12px;border-bottom:1px solid #eef1f5}
        .oo-table td{padding:9px 12px;border-bottom:1px solid #f3f5f8;vertical-align:top}
        .oo-table tr:hover td{background:#f8fafc}
        .oo-table label{display:block;cursor:pointer;margin:0;font-weight:400}
        .oo-who{font-weight:700;color:#0f172a}
        .oo-sub{font-size:12px;color:#64748b}
        .oo-owe{display:inline-block;margin-top:2px;font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 6px}
        .oo-empty{padding:28px 16px;text-align:center;color:#64748b}
        @media(max-width:640px){.oo-table .oo-hide{display:none}}
    </style>

    <a href="{{ $backUrl }}" class="text-sm">&larr; Needs attention</a>
    <h4 class="mt-1 mb-1 font-semibold">Tidy up old orders</h4>
    <p class="text-slate-500 text-sm mb-4" style="max-width:820px">
        Orders more than {{ $staleDays }} days past their promised date, or ready for more than {{ $staleDays }} days, are no longer chased
        on Needs attention. Most were collected before anyone recorded it: tick those and mark them collected. Each one goes on the order's
        history with your name. An order that really was cancelled: open it and cancel it there. ({{ $staleDays }} days is set in Settings &rarr; Reminders.)
    </p>

    <div class="oo-card">
        @if($orders->isEmpty())
            <div class="oo-empty"><i class="fas fa-check-circle text-green-700 mr-1"></i> No old open orders. All tidy.</div>
        @else
            <form class="oo-bar" wire:submit.prevent="markCollected">
                <button type="button" wire:click="selectAll">Tick all {{ $orders->count() }}</button>
                @if($selected)<button type="button" wire:click="selectNone">Clear</button>@endif
                <input type="text" wire:model="note" maxlength="500" placeholder="Note for the history, e.g. collected before the system tracked it (optional)">
                <button type="submit" class="oo-go" @disabled(! $selected)
                        wire:confirm="Mark {{ count($selected) }} {{ \Illuminate\Support\Str::plural('order', count($selected)) }} as collected?">
                    <i class="fas fa-check"></i> Mark {{ count($selected) ?: '' }} collected
                </button>
            </form>
            @error('selected')<div class="text-red-700 text-sm px-4 pt-2">{{ $message }}</div>@enderror

            <div class="ui-table-wrap">
                <table class="oo-table">
                    <thead><tr><th style="width:36px"></th><th>Patient · order</th><th>Status</th><th class="oo-hide">Promised</th><th class="oo-hide">Placed</th></tr></thead>
                    <tbody>
                        @foreach($orders as $order)
                            @php
                                $owes = ($order->sale_id || ! $order->refraction_id) ? round($order->total - (float) $order->paid_amount, 2) : 0;
                                $ready = in_array($order->status, \App\Models\LensOrder::READY, true);
                            @endphp
                            <tr wire:key="old-{{ $order->id }}">
                                <td><input type="checkbox" id="old-{{ $order->id }}" value="{{ $order->id }}" wire:model.live="selected"></td>
                                <td>
                                    <label for="old-{{ $order->id }}">
                                        <span class="oo-who">{{ $order->display_customer_name }}</span> · {{ $order->order_id }}
                                        @if($order->display_customer_phone)<div class="oo-sub">{{ $order->display_customer_phone }}</div>@endif
                                        @if($owes > 0)<span class="oo-owe" title="Marking it collected leaves this owing; balance reminders may follow">{{ currency() }} {{ number_format($owes, 2) }} still to pay</span>@endif
                                    </label>
                                </td>
                                <td>
                                    {{ $order->status }}
                                    <div class="oo-sub">{{ $ready ? 'Ready since ' . ($order->ready_at ?? $order->updated_at)->format('j M Y') : 'Not ready' }}</div>
                                </td>
                                <td class="oo-hide">{{ $order->pickUpDate ? \Illuminate\Support\Carbon::parse($order->pickUpDate)->format('j M Y') : '—' }}</td>
                                <td class="oo-hide">{{ $order->created_at?->format('j M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
