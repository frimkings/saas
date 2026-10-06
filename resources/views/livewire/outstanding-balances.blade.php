@php
    $money = fn ($amount) => currency().' '.number_format((float) $amount, 2);
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500';
    $figures = fn ($sale, $paidLabel, $dueLabel) => [
        ['Order total', $sale->total_amount, 'border-slate-200 bg-slate-50 text-slate-900'],
        [$paidLabel, $sale->amount_paid, 'border-green-200 bg-green-50 text-green-700'],
        [$dueLabel, $sale->remaining_balance, 'border-red-200 bg-red-50 text-red-700'],
    ];
@endphp
<section class="clinic-ui ui-page space-y-5">
    <div class="ui-heading">
        <div>
            <h1>Outstanding balances</h1>
            <p class="ui-muted">Orders on hold. Collect the balance to release the items to the customer.</p>
        </div>
    </div>

    <div class="ui-panel flex flex-wrap items-center gap-3 p-3">
        <div class="relative min-w-[16rem] flex-1">
            <i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
            <input type="search" class="ui-input !pl-9 !pr-9" placeholder="Search by patient name, PX number or transaction ID…" aria-label="Search balances"
                   wire:model.live.debounce.300ms="searchQuery">
            @if($searchQuery)
                <button type="button" class="absolute inset-y-0 right-0 px-3 text-slate-400 hover:text-slate-700" wire:click="$set('searchQuery','')" aria-label="Clear search"><i class="fas fa-times" aria-hidden="true"></i></button>
            @endif
        </div>
        <select class="ui-input !w-auto !pr-9" wire:model.live="perPage" aria-label="Rows per page">
            <option value="10">10 / page</option>
            <option value="15">15 / page</option>
            <option value="25">25 / page</option>
        </select>
    </div>

    <section class="ui-panel">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transaction ID</th>
                        <th>Patient</th>
                        <th>Items</th>
                        <th class="ui-number">Total</th>
                        <th class="ui-number">Paid</th>
                        <th class="ui-number">Balance</th>
                        <th>Progress</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($balances as $sale)
                        @php
                            $pct = $sale->patient_share > 0 ? round(((float) $sale->amount_paid / $sale->patient_share) * 100) : 0;
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap text-slate-500">
                                {{ $sale->created_at->format('d M Y') }}
                                <span class="block text-xs">{{ $sale->created_at->format('h:i A') }}</span>
                            </td>
                            <td class="font-mono text-xs text-teal-800">{{ $sale->transaction_id }}</td>
                            <td>
                                @if($sale->patient)
                                    <p class="font-semibold">{{ $sale->patient->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $sale->patient->pxnumber }}</p>
                                @else
                                    <span class="text-slate-500">Walk-in</span>
                                @endif
                            </td>
                            <td>
                                @foreach($sale->items->take(2) as $item)
                                    <span class="block max-w-[140px] truncate text-xs">{{ $item->product->name ?? 'N/A' }} <span class="text-slate-500">x{{ $item->prescribed_quantity }}</span></span>
                                @endforeach
                                @if($sale->items->count() > 2)
                                    <span class="text-xs text-slate-500">+{{ $sale->items->count() - 2 }} more</span>
                                @endif
                            </td>
                            <td class="ui-number font-semibold">
                                {{ $money($sale->total_amount) }}
                                @if((float) $sale->insurer_amount > 0)
                                    <span class="block text-xs font-normal text-sky-700">Insurer pays {{ $money($sale->insurer_amount) }}</span>
                                @endif
                            </td>
                            <td class="ui-number font-semibold text-green-700">{{ $money($sale->amount_paid) }}</td>
                            <td class="ui-number font-semibold text-red-700">{{ $money($sale->remaining_balance) }}</td>
                            <td class="min-w-[110px]">
                                <div class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuenow="{{ min(100, $pct) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Paid">
                                    <div class="h-full bg-amber-400" style="width: {{ min(100, $pct) }}%"></div>
                                </div>
                                <span class="text-xs text-slate-500">{{ $pct }}% paid</span>
                            </td>
                            <td>
                                <div class="flex justify-center gap-1 whitespace-nowrap">
                                    <button type="button" class="ui-button ui-button-secondary" wire:click.prevent="openHistory({{ $sale->id }})"
                                            wire:loading.attr="disabled" wire:target="openHistory({{ $sale->id }})" title="View payment history" aria-label="Payment history for {{ $sale->transaction_id }}">
                                        <i class="fas fa-history" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="ui-button ui-button-primary" wire:click.prevent="openCollect({{ $sale->id }})"
                                            wire:loading.attr="disabled" wire:target="openCollect({{ $sale->id }})" title="Collect payment">
                                        <i class="fas fa-plus-circle" aria-hidden="true"></i>Collect
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="ui-empty text-slate-500">
                                <i class="fas fa-check-circle mb-3 block text-4xl text-green-500" aria-hidden="true"></i>
                                No outstanding balances.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($balances->hasPages())
            <div class="border-t border-slate-200 px-4 py-2">{{ $balances->links() }}</div>
        @endif
    </section>

    {{-- ── Payment history ─────────────────────────────────────────── --}}
    @if($showHistoryModal && $historyForSaleId && $historyForSale)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" x-data x-on:keydown.escape.window="$wire.closeHistoryModal()">
            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="history-dialog-title">
                <div class="ui-panel-heading">
                    <h2 id="history-dialog-title"><i class="fas fa-history mr-2 text-teal-700" aria-hidden="true"></i>Payment history · <span class="font-mono text-sm">{{ $historyForSale->transaction_id }}</span></h2>
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeHistoryModal" aria-label="Close dialog">Close</button>
                </div>
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach($figures($historyForSale, 'Total paid', 'Remaining') as [$figureLabel, $amount, $tone])
                            <div class="rounded-lg border p-3 text-center {{ $tone }}">
                                <p class="text-lg font-semibold">{{ $money($amount) }}</p>
                                <p class="text-xs text-slate-500">{{ $figureLabel }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-sm">
                        <span class="text-xs font-semibold uppercase text-slate-500">Patient:</span>
                        <span class="ml-1 font-semibold">{{ $historyForSale->customer_display_name }}</span>
                        @if($historyForSale->patient)<span class="ml-2 text-xs text-slate-500">{{ $historyForSale->patient->pxnumber }}</span>@endif
                    </p>

                    @if($historyForSale->paymentTransactions->isEmpty())
                        <p class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600"><i class="fas fa-info-circle mr-1" aria-hidden="true"></i>No payment transactions recorded yet.</p>
                    @else
                        <div class="ui-table-wrap rounded-lg border border-slate-200">
                            <table class="ui-table">
                                <thead>
                                    <tr><th>#</th><th>Date &amp; time</th><th>Method</th><th>Collected by</th><th>Notes</th><th class="ui-number">Amount</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($historyForSale->paymentTransactions as $i => $txn)
                                        <tr>
                                            <td class="text-slate-500">{{ $i + 1 }}</td>
                                            <td class="whitespace-nowrap">{{ $txn->created_at->format('d M Y') }}<span class="block text-xs text-slate-500">{{ $txn->created_at->format('h:i A') }}</span></td>
                                            <td><span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ ucfirst($txn->payment_method) }}</span></td>
                                            <td>{{ $txn->collectedBy->name ?? '—' }}</td>
                                            <td class="text-xs text-slate-500">{{ $txn->notes ?? '—' }}</td>
                                            <td class="ui-number font-semibold text-green-700">+{{ $money($txn->amount) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-slate-50">
                                    <tr>
                                        <td colspan="5" class="text-right text-xs font-semibold text-slate-500">Total paid</td>
                                        <td class="ui-number font-semibold text-green-700">{{ $money($historyForSale->paymentTransactions->sum('amount')) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeHistoryModal">Close</button>
                    <button type="button" class="ui-button ui-button-primary" wire:click="switchToCollectFromHistory"><i class="fas fa-plus-circle" aria-hidden="true"></i>Collect payment</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Collect payment ─────────────────────────────────────────── --}}
    @if($showModal && $selectedSaleId && $selectedSale)
        @php
            $newBalance = max(0, $selectedSale->remaining_balance - (float) $collectAmount);
            $willFullyPay = (float) $collectAmount > 0 && $newBalance <= 0.001;
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" x-data x-on:keydown.escape.window="$wire.closeModal()">
            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="collect-dialog-title">
                <div class="ui-panel-heading">
                    <h2 id="collect-dialog-title"><i class="fas fa-wallet mr-2 text-teal-700" aria-hidden="true"></i>Collect payment</h2>
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeModal" aria-label="Close dialog">Close</button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach($figures($selectedSale, 'Already paid', 'Balance due') as [$figureLabel, $amount, $tone])
                            <div class="rounded-lg border p-3 text-center {{ $tone }}">
                                <p class="text-xl font-semibold">{{ $money($amount) }}</p>
                                <p class="text-xs text-slate-500">{{ $figureLabel }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="{{ $label }}">Patient</p>
                            <p class="font-semibold">{{ $selectedSale->customer_display_name }}</p>
                            @if($selectedSale->patient)<p class="text-xs text-slate-500">{{ $selectedSale->patient->pxnumber }}</p>@endif
                        </div>
                        <div>
                            <p class="{{ $label }}">Transaction ID</p>
                            <p class="font-mono text-sm text-teal-800">{{ $selectedSale->transaction_id }}</p>
                            <p class="text-xs text-slate-500">{{ $selectedSale->created_at->format('d M Y, h:i A') }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="{{ $label }}">Items on hold</p>
                        <div class="ui-table-wrap rounded-lg border border-slate-200">
                            <table class="ui-table">
                                <thead><tr><th>Product</th><th class="text-center">Qty</th><th class="ui-number">Price</th><th class="ui-number">Subtotal</th></tr></thead>
                                <tbody>
                                    @foreach($selectedSale->items as $item)
                                        <tr>
                                            <td>{{ $item->product->name ?? 'N/A' }}</td>
                                            <td class="text-center">{{ $item->prescribed_quantity }}</td>
                                            <td class="ui-number">{{ $money($item->selling_price) }}</td>
                                            <td class="ui-number">{{ $money($item->subtotal) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @if($selectedSale->paymentTransactions->isNotEmpty())
                        <div>
                            <p class="{{ $label }}">Payment history</p>
                            @foreach($selectedSale->paymentTransactions as $txn)
                                <div class="flex items-center justify-between gap-2 border-b border-slate-100 py-1.5 text-sm">
                                    <span class="text-xs text-slate-500">
                                        {{ $txn->created_at->format('d M Y, h:i A') }} - {{ ucfirst($txn->payment_method) }}
                                        @if($txn->collectedBy)({{ $txn->collectedBy->name }})@endif
                                        @if($txn->notes)<em> | {{ $txn->notes }}</em>@endif
                                    </span>
                                    <span class="whitespace-nowrap font-semibold text-green-700">+{{ $money($txn->amount) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-[5fr_4fr_3fr]">
                        <div>
                            <label for="collect-amount" class="{{ $label }}">Amount to collect <span class="text-red-600">*</span></label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">{{ currency() }}</span>
                                <input id="collect-amount" type="number" class="ui-input !pl-12" wire:model.live.debounce.300ms="collectAmount"
                                       step="0.01" min="0.01" max="{{ $selectedSale->remaining_balance }}" placeholder="0.00"
                                       aria-invalid="{{ $errors->has('collectAmount') ? 'true' : 'false' }}">
                            </div>
                            @error('collectAmount')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="collect-method" class="{{ $label }}">Payment method</label>
                            <select id="collect-method" class="ui-input" wire:model.live="paymentMethod">
                                @foreach(\App\Support\PaymentMethods::active(\App\Support\PaymentMethods::CLINIC) as $methodKey => $methodLabel)
                                    <option value="{{ $methodKey }}">{{ $methodLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="collect-notes" class="{{ $label }}">Notes</label>
                            <input id="collect-notes" type="text" class="ui-input" wire:model.live.debounce.300ms="paymentNotes" placeholder="Optional">
                        </div>
                    </div>

                    @if((float) $collectAmount > 0)
                        <div @class(['rounded-lg border px-3 py-2 text-sm', 'border-green-200 bg-green-50 text-green-800' => $willFullyPay, 'border-sky-200 bg-sky-50 text-sky-900' => ! $willFullyPay]) role="status">
                            @if($willFullyPay)
                                <i class="fas fa-check-circle mr-1" aria-hidden="true"></i><strong>Fully paid.</strong> Items will be released to the customer.
                            @else
                                <i class="fas fa-info-circle mr-1" aria-hidden="true"></i>Remaining balance after this payment: <strong>{{ $money($newBalance) }}</strong>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" class="ui-button ui-button-secondary" wire:click="closeModal">Cancel</button>
                    <button type="button" class="ui-button ui-button-primary"
                            @if($willFullyPay) onclick="window.releasedReceiptWindow = window.open('about:blank', '_blank', 'width=302,height=650')" @endif
                            wire:click="collectPayment" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="collectPayment"><i class="fas fa-check mr-1" aria-hidden="true"></i>{{ $willFullyPay ? 'Record & release items' : 'Record payment' }}</span>
                        <span wire:loading wire:target="collectPayment"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <script>
    document.addEventListener('livewire:init', function () {
        window.addEventListener('print-released-receipt', function (event) {
            const receiptWindow = window.releasedReceiptWindow && !window.releasedReceiptWindow.closed
                ? window.releasedReceiptWindow
                : window.open('about:blank', '_blank', 'width=302,height=650');

            if (!receiptWindow) {
                window.appAlert('Pop-up blocked', 'Please allow pop-ups for this site to print the receipt.', 'warning');
                return;
            }

            receiptWindow.location.href = event.detail.url;
            receiptWindow.onload = function () {
                setTimeout(function () {
                    receiptWindow.focus();
                    receiptWindow.print();
                    window.releasedReceiptWindow = null;
                }, 500);
            };
        });
    });
    </script>
</section>
