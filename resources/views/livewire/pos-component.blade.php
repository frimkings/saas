<section>
<div x-data="{
    checkoutInProgress: false,
    pendingCartsOpen: false,
    darkMode: false,
    initTheme() {
        const saved = localStorage.getItem('posDarkMode');
        this.darkMode = saved === null ? false : saved === '1';
    },
    toggleDarkMode() {
        this.darkMode = !this.darkMode;
        localStorage.setItem('posDarkMode', this.darkMode ? '1' : '0');
    },
    handleConfirmCheckout() { @this.checkout(); },
    handleSellWithoutPendingDiscount() { @this.sellWithoutPendingDiscount(); }
}"
x-init="initTheme()"
@confirm-checkout.window="handleConfirmCheckout()"
@sell-without-pending-discount.window="handleSellWithoutPendingDiscount()"
@show-pending-carts.window="pendingCartsOpen = true"
@close-pending-carts-modal.window="pendingCartsOpen = false"
@pos-cart-loaded.window="$nextTick(() => document.querySelector('.pos-checkout')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))"
>

{{-- ===================================================================
     RECEIPT MODAL
     =================================================================== --}}
@if($showReceipt && $receiptData)
<div class="pos-overlay" style="z-index:9999;">
    <div class="pos-receipt-dialog">
        <div class="pos-receipt-header">
            <span><i class="fas fa-check-circle me-2"></i>Sale Complete — Receipt Ready</span>
            <button wire:click="dismissReceipt"><i class="fas fa-times"></i></button>
        </div>
        <div class="pos-receipt-body">
            <div class="pos-receipt-paper">
                <div style="text-align:center; padding-bottom:8px; border-bottom:2px dashed #333; margin-bottom:8px;">
                    @if(!empty($receiptData['clinic_logo']))
                        <img src="{{ $receiptData['clinic_logo'] }}" style="max-width:110px; max-height:58px; object-fit:contain; margin-bottom:5px;" alt="Clinic Logo">
                    @endif
                    <div style="font-size:14px; font-weight:900;">{{ $receiptData['clinic_name'] }}</div>
                    @if($receiptData['clinic_address'])<div style="font-size:10px; color:#555;">{{ $receiptData['clinic_address'] }}</div>@endif
                    @if($receiptData['clinic_contact'])<div style="font-size:10px;">Tel: {{ $receiptData['clinic_contact'] }}</div>@endif
                    @if($receiptData['clinic_email'])<div style="font-size:10px;">{{ $receiptData['clinic_email'] }}</div>@endif
                    <div style="margin-top:6px; font-size:10px;"><strong>TXN#: {{ $receiptData['transaction_id'] }}</strong></div>
                    <div style="font-size:10px; color:#555;">{{ $receiptData['created_at'] }}</div>
                </div>
                @if($receiptData['patient'])
                    <div style="font-size:10px; padding-bottom:6px; border-bottom:1px dashed #999; margin-bottom:6px;">
                        <div style="font-weight:bold; margin-bottom:2px;">PATIENT:</div>
                        <div>{{ $receiptData['patient']['name'] }}</div>
                        <div>Tel: {{ $receiptData['patient']['contact'] }}</div>
                        <div>ID: {{ $receiptData['patient']['pxnumber'] }}</div>
                    </div>
                @elseif(!empty($receiptData['customer_name']))
                    <div style="font-size:10px; padding-bottom:6px; border-bottom:1px dashed #999; margin-bottom:6px;">
                        <div style="font-weight:bold; margin-bottom:2px;">CUSTOMER:</div>
                        <div>{{ $receiptData['customer_name'] }}</div>
                    </div>
                @endif
                <table style="width:100%; border-collapse:collapse; margin-bottom:6px;">
                    <thead><tr style="border-bottom:1px solid #333;">
                        <th style="text-align:left; padding:3px 2px; font-size:10px; width:46%;">ITEM</th>
                        <th style="text-align:center; padding:3px 2px; font-size:10px; width:10%;">QTY</th>
                        <th style="text-align:right; padding:3px 2px; font-size:10px; width:22%;">PRICE</th>
                        <th style="text-align:right; padding:3px 2px; font-size:10px; width:22%;">TOTAL</th>
                    </tr></thead>
                    <tbody>
                        @foreach($receiptData['items'] as $item)
                            <tr style="border-bottom:1px dotted #eee;">
                                <td style="padding:3px 2px; font-size:10px;">{{ \Illuminate\Support\Str::limit($item['name'], 18) }}</td>
                                <td style="text-align:center; padding:3px 2px; font-size:10px;">{{ $item['quantity'] }}</td>
                                <td style="text-align:right; padding:3px 2px; font-size:10px;">{{ currency() }} {{ number_format($item['selling_price'], 2) }}</td>
                                <td style="text-align:right; padding:3px 2px; font-size:10px;">{{ currency() }} {{ number_format($item['subtotal'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="border-top:2px solid #333; padding-top:6px; margin-top:4px;">
                    @if(isset($receiptData['discount_amount']) && $receiptData['discount_amount'] > 0)
                        <div style="display:flex; justify-content:space-between; font-size:11px; color:#777; margin-bottom:2px;"><span>SUBTOTAL</span><span>{{ currency() }} {{ number_format($receiptData['gross_amount'], 2) }}</span></div>
                        <div style="display:flex; justify-content:space-between; font-size:11px; color:#e67e22; font-weight:bold; margin-bottom:4px;">
                            <span>DISCOUNT ({{ $receiptData['discount_type'] === 'percentage' ? number_format($receiptData['discount_value'], 0).'%' : currency().' '.number_format($receiptData['discount_value'], 2) }})</span>
                            <span>-{{ currency() }} {{ number_format($receiptData['discount_amount'], 2) }}</span>
                        </div>
                    @endif
                    <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:900;"><span>TOTAL</span><span>{{ currency() }} {{ number_format($receiptData['total_amount'], 2) }}</span></div>
                    @if(!empty($receiptData['payments']))
                        @foreach($receiptData['payments'] as $p)
                            <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:3px;">
                                <span>PAID <span style="font-size:9px; color:#777; text-transform:uppercase;">({{ $p['method'] }})</span></span>
                                <span>{{ currency() }} {{ number_format($p['amount'], 2) }}</span>
                            </div>
                        @endforeach
                        @if(count($receiptData['payments']) > 1)
                            <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:2px; font-weight:bold; border-top:1px dashed #ccc; padding-top:2px;"><span>TOTAL PAID</span><span>{{ currency() }} {{ number_format($receiptData['amount_paid'], 2) }}</span></div>
                        @endif
                    @elseif(isset($receiptData['amount_paid']) && $receiptData['amount_paid'] > 0)
                        <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:3px;"><span>PAID</span><span>{{ currency() }} {{ number_format($receiptData['amount_paid'], 2) }}</span></div>
                    @endif
                    @if($receiptData['change'] > 0)
                        <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:2px; font-weight:bold; color:#1a7a4a;"><span>CHANGE</span><span>{{ currency() }} {{ number_format($receiptData['change'], 2) }}</span></div>
                    @endif
                    @if(isset($receiptData['balance']) && $receiptData['balance'] > 0)
                        <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:4px; font-weight:bold; color:#c0392b; border-top:1px dashed #ccc; padding-top:4px;"><span>BALANCE DUE</span><span>{{ currency() }} {{ number_format($receiptData['balance'], 2) }}</span></div>
                    @endif
                </div>
                <div style="text-align:center; margin-top:10px; padding-top:6px; border-top:1px dashed #999; font-size:9px; color:#555;">
                    <div>Thank you for your business!</div>
                    <div>Please keep this receipt for your records.</div>
                    <div style="margin-top:4px;">Served by: <strong>{{ $receiptData['served_by'] }}</strong></div>
                    <div>{{ now()->format('M d, Y h:i A') }}</div>
                </div>
            </div>
        </div>
        @php
            $printData = array_merge($receiptData, ['printed_at' => now()->format('M d, Y h:i A')]);
            $encodedPrintData = base64_encode(json_encode($printData));
        @endphp
        <div class="pos-receipt-footer" id="receipt-modal-footer" data-receipt="{{ $encodedPrintData }}">
            <button type="button" class="pos-btn pos-btn--success pos-btn--lg" onclick="return printReceiptFromDom(event)">
                <i class="fas fa-print me-2"></i>Print Receipt
            </button>
            @if(!empty($receiptData['sale_id']))
                <a class="pos-btn pos-btn--pdf pos-btn--lg"
                   href="{{ route('cashier.receipt.pdf', ['saleId' => $receiptData['sale_id'], 'change' => $receiptData['change'] ?? 0]) }}"
                   target="_blank"
                   rel="noopener">
                    <i class="fas fa-file-pdf me-2"></i>Save PDF
                </a>
            @endif
            <button type="button" class="pos-btn pos-btn--ghost" wire:click="dismissReceipt">
                <i class="fas fa-times me-1"></i>Close
            </button>
        </div>
    </div>
</div>
@endif

{{-- Hidden print iframe --}}
<iframe id="receipt-iframe" style="display:none; position:fixed; top:0; left:0; width:0; height:0; border:none; visibility:hidden;"></iframe>

{{-- ===================================================================
     PRINT FUNCTIONS (unchanged)
     =================================================================== --}}
<script>
window.buildAndPrint = function(d) {
    if (!d) { alert('Receipt data not ready. Please try again.'); return; }
    var itemRows = '';
    d.items.forEach(function(item) {
        var name  = item.name.length > 18 ? item.name.substring(0, 18) + '...' : item.name;
        var price = '{{ currency() }} ' + parseFloat(item.selling_price).toFixed(2);
        var total = '{{ currency() }} ' + parseFloat(item.subtotal).toFixed(2);
        itemRows += '<tr><td style="padding:3px 2px;font-size:12px;font-weight:bold;">' + name + '</td><td style="text-align:center;padding:3px 2px;">' + item.quantity + '</td><td style="text-align:right;padding:3px 2px;">' + price + '</td><td style="text-align:right;padding:3px 2px;">' + total + '</td></tr>';
    });
    var patientBlock = '';
    if (d.patient) {
        patientBlock = '<div style="font-size:10px;padding-bottom:6px;border-bottom:1px dashed #000;margin-bottom:6px;"><strong>PATIENT:</strong><br>' + d.patient.name + '<br>Tel: ' + d.patient.contact + '<br>ID: ' + d.patient.pxnumber + '</div>';
    } else if (d.customer_name) {
        patientBlock = '<div style="font-size:10px;padding-bottom:6px;border-bottom:1px dashed #000;margin-bottom:6px;"><strong>CUSTOMER:</strong><br>' + d.customer_name + '</div>';
    }
    var subtotalRow = '', discountRow = '';
    if (d.discount_amount && parseFloat(d.discount_amount) > 0) {
        subtotalRow = '<div style="display:flex;justify-content:space-between;font-size:12px;color:#777;margin-bottom:2px;"><span>SUBTOTAL</span><span>{{ currency() }} ' + parseFloat(d.gross_amount).toFixed(2) + '</span></div>';
        var discLabel = d.discount_type === 'percentage' ? 'DISCOUNT (' + parseFloat(d.discount_value).toFixed(0) + '%)' : 'DISCOUNT ({{ currency() }} ' + parseFloat(d.discount_value).toFixed(2) + ')';
        discountRow = '<div style="display:flex;justify-content:space-between;font-size:12px;color:#e67e22;font-weight:bold;margin-bottom:4px;"><span>' + discLabel + '</span><span>-{{ currency() }} ' + parseFloat(d.discount_amount).toFixed(2) + '</span></div>';
    }
    var paidRow = '';
    if (d.payments && d.payments.length > 0) {
        d.payments.forEach(function(p) {
            paidRow += '<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:3px;"><span>PAID <span style="font-size:10px;color:#777;text-transform:uppercase;">(' + p.method + ')</span></span><span>{{ currency() }} ' + parseFloat(p.amount).toFixed(2) + '</span></div>';
        });
        if (d.payments.length > 1) {
            paidRow += '<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:2px;font-weight:bold;border-top:1px dashed #999;padding-top:2px;"><span>TOTAL PAID</span><span>{{ currency() }} ' + parseFloat(d.amount_paid).toFixed(2) + '</span></div>';
        }
    } else if (parseFloat(d.amount_paid) > 0) {
        paidRow = '<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:3px;"><span>PAID</span><span>{{ currency() }} ' + parseFloat(d.amount_paid).toFixed(2) + '</span></div>';
    }
    var changeRow  = parseFloat(d.change) > 0 ? '<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:2px;font-weight:bold;"><span>CHANGE</span><span>{{ currency() }} ' + parseFloat(d.change).toFixed(2) + '</span></div>' : '';
    var balanceRow = d.balance && parseFloat(d.balance) > 0 ? '<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:4px;font-weight:bold;color:#c0392b;border-top:1px dashed #999;padding-top:4px;"><span>BALANCE DUE</span><span>{{ currency() }} ' + parseFloat(d.balance).toFixed(2) + '</span></div>' : '';
    var contactLines = '';
    if (d.clinic_contact) contactLines += '<div>Tel: ' + d.clinic_contact + '</div>';
    if (d.clinic_email)   contactLines += '<div>' + d.clinic_email + '</div>';
    var addressLine = d.clinic_address ? '<div style="font-size:10px;">' + d.clinic_address + '</div>' : '';
    var logoLine = d.clinic_logo ? '<img src="' + d.clinic_logo + '" style="max-width:35mm;max-height:18mm;object-fit:contain;margin-bottom:4px;">' : '';
    var printedAt = d.printed_at || new Date().toLocaleString();
    var receiptContent =
        '<div style="text-align:center;">' + logoLine + '<div style="font-size:15px;font-weight:900;">' + d.clinic_name + '</div>' + addressLine + contactLines + '</div>'
        + '<div style="border-top:2px dashed #000;margin:6px 0;"></div>'
        + '<div style="text-align:center;"><strong>TXN#: ' + d.transaction_id + '</strong><br><span style="font-size:12px;">' + d.created_at + '</span></div>'
        + '<div style="border-top:1px dashed #000;margin:6px 0;"></div>'
        + patientBlock
        + '<table style="width:100%;border-collapse:collapse;"><thead><tr style="border-bottom:1px solid #000;"><th style="padding:4px 2px;font-size:11px;text-align:left;width:48%;">ITEM</th><th style="padding:4px 2px;font-size:11px;text-align:center;width:10%;">QTY</th><th style="padding:4px 2px;font-size:11px;text-align:right;width:21%;">PRICE</th><th style="padding:4px 2px;font-size:11px;text-align:right;width:21%;">TOTAL</th></tr></thead><tbody>' + itemRows + '</tbody></table>'
        + '<div style="border-top:2px solid #000;padding-top:6px;margin-top:4px;">' + subtotalRow + discountRow + '<div style="display:flex;justify-content:space-between;margin:3px 0;font-size:14px;font-weight:bold;"><span>TOTAL</span><span>{{ currency() }} ' + parseFloat(d.total_amount).toFixed(2) + '</span></div>' + paidRow + changeRow + balanceRow + '</div>'
        + '<div style="text-align:center;font-size:11px;border-top:1px dashed #000;padding-top:6px;margin-top:10px;"><p>Thank you for your business!</p><p>Please keep this receipt for your records.</p><br><p>Served by: <strong>' + d.served_by + '</strong></p><p>' + printedAt + '</p></div>';

    ['__pos_receipt_print__', '__pos_receipt_print_style__'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.remove();
    });
    var printStyle = document.createElement('style');
    printStyle.id = '__pos_receipt_print_style__';
    printStyle.textContent = '@media print { @page { size: 80mm auto; margin: 0; } html, body { width: 80mm !important; margin: 0 !important; background: #fff !important; } body > *:not(#__pos_receipt_print__) { display: none !important; visibility: hidden !important; } #__pos_receipt_print__ { display: block !important; visibility: visible !important; position: static !important; width: 80mm !important; margin: 0 !important; background: #fff !important; color: #000 !important; } }';
    document.head.appendChild(printStyle);
    var printDiv = document.createElement('div');
    printDiv.id = '__pos_receipt_print__';
    printDiv.style.cssText = 'display:block; position:fixed; left:-10000px; top:0; width:80mm; background:#fff; color:#000; z-index:-1;';
    printDiv.innerHTML = '<div style="font-family:\'Courier New\',monospace;font-size:12px;line-height:1.5;color:#000;background:#fff;width:80mm;padding:4mm;">' + receiptContent + '</div>';
    document.body.appendChild(printDiv);
    setTimeout(function() {
        printDiv.style.left = '0';
        printDiv.style.position = 'static';
        window.print();
        setTimeout(function() {
            var el = document.getElementById('__pos_receipt_print__');
            var st = document.getElementById('__pos_receipt_print_style__');
            if (el) el.remove();
            if (st) st.remove();
        }, 1000);
    }, 300);
};
window.printReceiptFromDom = function(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    var footer = document.getElementById('receipt-modal-footer');
    if (!footer) { window.buildAndPrint(window._receiptData); return false; }
    var raw = footer.getAttribute('data-receipt');
    if (!raw)   { window.buildAndPrint(window._receiptData); return false; }
    try {
        window.buildAndPrint(JSON.parse(atob(raw)));
    } catch(e) {
        console.error('Receipt data parse error:', e);
        window.buildAndPrint(window._receiptData);
    }
    return false;
};
</script>

{{-- ===================================================================
     MAIN POS SHELL
     =================================================================== --}}
<div class="pos-shell" :class="darkMode ? 'pos-shell--dark' : 'pos-shell--light'">

    {{-- TOP BAR --}}
    <div class="pos-topbar">
        <div class="pos-topbar__brand">
            <span class="pos-topbar__title">Point of Sale</span>
            <span class="pos-topbar__sub">Cashier Desk</span>
        </div>
        <div class="pos-topbar__actions">
            <div class="pos-chip"><i class="fas fa-user-circle"></i> {{ Auth::user()->name }}</div>
            <div class="pos-chip"><i class="fas fa-clock"></i> <span id="currentTime"></span></div>
            <button class="pos-topbtn" wire:click="loadAllPendingCarts" {{ $purchaseMode === 'direct' ? 'disabled' : '' }}>
                <i class="fas fa-layer-group"></i>
                <span>Doctor Carts</span>
                @if(($pendingPrescriptionCartCount ?? 0) > 0)
                    <strong class="pos-topbtn__badge">{{ $pendingPrescriptionCartCount }}</strong>
                @endif
            </button>
            <button class="pos-topbtn" wire:click="loadApprovedDiscounts">
                <i class="fas fa-tags"></i>
                <span>Approved Discounts</span>
                @if(($approvedDiscountCount ?? 0) > 0)
                    <strong class="pos-topbtn__badge pos-topbtn__badge--success">{{ $approvedDiscountCount }}</strong>
                @endif
            </button>
            <button type="button" class="pos-topbtn" @click="toggleDarkMode()" :title="darkMode ? 'Switch to light mode' : 'Switch to dark mode'">
                <i class="fas" :class="darkMode ? 'fa-sun' : 'fa-moon'"></i>
                <span x-text="darkMode ? 'Light Mode' : 'Dark Mode'"></span>
            </button>
            <button class="pos-topbtn pos-topbtn--danger" wire:click="clearCart" {{ count($cart) == 0 ? 'disabled' : '' }}>
                <i class="fas fa-trash-alt"></i>
                <span>Clear Cart</span>
            </button>
        </div>
    </div>

    {{-- BODY --}}
    <div class="pos-body">

        {{-- ==================== LEFT: PRODUCTS ==================== --}}
        <div class="pos-products">

            {{-- Search --}}
            <div class="pos-search-bar">
                <i class="fas fa-search pos-search-icon"></i>
                <input class="pos-search-input"
                       type="text"
                       placeholder="Search by name or batch no…"
                       wire:model.live.debounce.300ms="productSearchTerm">
                <span class="pos-search-count">{{ $products->total() }} items</span>
            </div>

            {{-- Category pills --}}
            <div class="pos-cats">
                <button class="pos-cat {{ !$selectedCategoryId ? 'pos-cat--active' : '' }}"
                        wire:click="$set('selectedCategoryId', '')">
                    All
                </button>
                @foreach($categories as $cat)
                    <button class="pos-cat {{ $selectedCategoryId == $cat->id ? 'pos-cat--active' : '' }}"
                            wire:click="$set('selectedCategoryId', {{ $cat->id }})">
                        {{ $cat->name }}
                    </button>
                @endforeach
            </div>

            {{-- Product grid --}}
            <div class="pos-grid">
                @forelse($products as $product)
                    <div class="pos-product {{ $product->quantity <= 0 ? 'pos-product--oos' : '' }}"
                         wire:click="addToCart({{ $product->id }})">
                        <div class="pos-product__icon">
                            <i class="fas fa-pills"></i>
                        </div>
                        <div class="pos-product__name">{{ Str::limit($product->name, 28) }}</div>
                        <div class="pos-product__price">{{ currency() }} {{ number_format($product->selling_price, 2) }}</div>
                        <div class="pos-product__stock">
                            @if($product->quantity > 10)
                                <span class="pos-stock pos-stock--ok">{{ $product->quantity }}</span>
                            @elseif($product->quantity > 0)
                                <span class="pos-stock pos-stock--low">Low {{ $product->quantity }}</span>
                            @else
                                <span class="pos-stock pos-stock--out">Out of stock</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="pos-empty-state">
                        <i class="fas fa-box-open"></i>
                        <p>No products found</p>
                    </div>
                @endforelse
            </div>

            {{-- Pagination --}}
            <div class="pos-pagination">{{ $products->links() }}</div>

        </div>

        {{-- ==================== RIGHT: CHECKOUT ==================== --}}
        <div class="pos-checkout">

            {{-- Purchase mode / customer --}}
            <div class="pos-co-section pos-patient-section">
                <div class="pos-co-label"><i class="fas fa-user-circle"></i> Customer</div>
                <div class="pos-mode-switch">
                    <button type="button"
                            class="pos-mode-btn {{ $purchaseMode === 'patient' ? 'pos-mode-btn--active' : '' }}"
                            wire:click="selectPatientPurchaseMode">
                        <i class="fas fa-user-injured"></i> Registered Patient
                    </button>
                    <button type="button"
                            class="pos-mode-btn {{ $purchaseMode === 'direct' ? 'pos-mode-btn--active pos-mode-btn--direct' : '' }}"
                            wire:click="selectDirectPurchaseMode">
                        <i class="fas fa-shopping-bag"></i> Direct Purchase
                    </button>
                </div>

                @if($purchaseMode === 'direct')
                    <div class="pos-direct-panel">
                        <div class="pos-direct-panel__status">
                            <i class="fas fa-check-circle"></i>
                            <span>Direct Purchase</span>
                        </div>
                        <input class="pos-co-input"
                               type="text"
                               maxlength="150"
                               placeholder="Customer name (optional)"
                               wire:model="directCustomerName"
                               autocomplete="off">
                        <div class="pos-direct-panel__hint">
                            Leave blank for Walk-in. No patient record or Doctor Cart will be used.
                        </div>
                        @error('directCustomerName')
                            <div class="pos-direct-panel__error">{{ $message }}</div>
                        @enderror
                    </div>
                @elseif(!$patientId)
                    <div class="pos-patient-wrap" style="position:relative;">
                        <input class="pos-co-input"
                               type="text"
                               placeholder="Search by name or contact…"
                               wire:model.live.debounce.300ms="patientSearchTerm"
                               autocomplete="off">
                        @if($showPatientDropdown && count($searchResults) > 0)
                            <div class="pos-patient-drop">
                                @foreach($searchResults as $patient)
                                    <div class="pos-patient-row" wire:click="selectPatient({{ $patient['id'] }})">
                                        <div class="pos-patient-row__name">{{ $patient['name'] }}</div>
                                        <div class="pos-patient-row__meta">{{ $patient['contact'] }} &middot; {{ $patient['pxnumber'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    <div class="pos-patient-selected">
                        <div class="pos-patient-selected__info">
                            <i class="fas fa-user-check pos-patient-selected__icon"></i>
                            <div>
                                <div class="pos-patient-selected__name">{{ $patientSearchTerm }}</div>
                                <div class="pos-patient-selected__sub">Patient selected</div>
                            </div>
                        </div>
                        <button class="pos-icon-btn pos-icon-btn--danger" wire:click="clearPatient">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                @endif
            </div>

            {{-- Cart --}}
            <div class="pos-co-section pos-cart-section">
                <div class="pos-co-label">
                    <span><i class="fas fa-shopping-basket"></i> Cart</span>
                    <span class="pos-badge">{{ count($cart) }}</span>
                </div>

                @if(count($cart) > 0)
                    <div class="pos-cart-list">
                        @foreach($cart as $cartKey => $cartItem)
                            @php
                                $productId = is_array($cartItem) && !empty($cartItem['product_id'])
                                    ? $cartItem['product_id']
                                    : (is_numeric($cartKey) ? $cartKey : null);
                                $product         = $cartProducts[$productId] ?? null;
                                $quantity        = is_array($cartItem) ? $cartItem['quantity'] : $cartItem;
                                $frequency       = is_array($cartItem) ? ($cartItem['frequency'] ?? null) : null;
                                $eye             = is_array($cartItem) ? ($cartItem['eye'] ?? null) : null;
                                $wireCartKey     = addslashes((string) $cartKey);
                                $showDrugOptions = $product && $product->isDrugCategory();
                                $fromRx          = is_array($cartItem) && ($cartItem['from_prescription'] ?? false);
                                $isFrame         = is_array($cartItem) && ($cartItem['is_frame'] ?? false);
                                $isLocked        = $fromRx && !$isFrame; // prescription + non-frame = locked
                            @endphp
                            @if($product)
                                <div class="pos-cart-item {{ $isLocked ? 'pos-cart-item--locked' : '' }}" wire:key="cart-row-{{ $cartKey }}">
                                    <div class="pos-cart-item__row1">
                                        {{-- Rx badge --}}
                                        @if($fromRx)
                                            <span class="pos-rx-badge {{ $isFrame ? 'pos-rx-badge--frame' : '' }}" title="{{ $isFrame ? 'Prescription frame — editable' : 'Prescription item — locked' }}">
                                                {{ $isFrame ? 'Rx Frame' : 'Rx' }}
                                            </span>
                                        @endif
                                        <span class="pos-cart-item__name">{{ Str::limit($product->name, 30) }}</span>
                                        <span class="pos-cart-item__total">{{ currency() }} {{ number_format($quantity * $product->selling_price, 2) }}</span>
                                        @if($isLocked)
                                            {{-- Locked: show padlock, no remove --}}
                                            <span class="pos-lock-icon" title="Prescription item — cannot be removed"><i class="fas fa-lock"></i></span>
                                        @else
                                            <button class="pos-icon-btn pos-icon-btn--ghost pos-remove-btn"
                                                    wire:click="removeFromCart('{{ $wireCartKey }}')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        @endif
                                    </div>
                                    <div class="pos-cart-item__row2">
                                        <span class="pos-cart-item__price">{{ currency() }} {{ number_format($product->selling_price, 2) }} ea.</span>
                                        @if($isLocked)
                                            {{-- Locked: quantity read-only --}}
                                            <span class="pos-qty-locked">Qty: {{ $quantity }}</span>
                                        @else
                                            <div class="pos-qty">
                                                <button wire:click="updateQuantity('{{ $wireCartKey }}', {{ $quantity - 1 }})">−</button>
                                                <input type="number" value="{{ $quantity }}" min="1"
                                                       wire:change="updateQuantity('{{ $wireCartKey }}', $event.target.value)">
                                                <button wire:click="updateQuantity('{{ $wireCartKey }}', {{ $quantity + 1 }})">+</button>
                                            </div>
                                        @endif
                                        @if($showDrugOptions)
                                        <select class="pos-select"
                                                wire:change="updateCartItemMetadata('{{ $wireCartKey }}', $event.target.value, '{{ addslashes($eye ?? '') }}')">
                                            <option value="">Freq —</option>
                                            @foreach(\App\Enums\ProductFrequency::options() as $value => $label)
                                                <option value="{{ $value }}" {{ $frequency === $value ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <select class="pos-select"
                                                wire:change="updateCartItemMetadata('{{ $wireCartKey }}', '{{ addslashes($frequency ?? '') }}', $event.target.value)">
                                            <option value="">Eye —</option>
                                            <option value="OD" {{ in_array(strtoupper($eye ?? ''), ['OD','RIGHT']) ? 'selected' : '' }}>OD (Right)</option>
                                            <option value="OS" {{ in_array(strtoupper($eye ?? ''), ['OS','LEFT'])  ? 'selected' : '' }}>OS (Left)</option>
                                            <option value="OU" {{ in_array(strtoupper($eye ?? ''), ['OU','BOTH'])  ? 'selected' : '' }}>OU (Both)</option>
                                        </select>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="pos-cart-empty">
                        <i class="fas fa-shopping-cart"></i>
                        <p>Cart is empty — tap a product to add it.</p>
                    </div>
                @endif

                {{-- Add Frame panel — visible only when a prescription cart is loaded --}}
                @if($hasPrescriptionCart)
                <div class="pos-frame-adder">
                    <div class="pos-frame-adder__header">
                        <i class="fas fa-glasses mr-1"></i>
                        Add / Change Frame
                        <span class="pos-frame-adder__hint">Prescription cart — only Frame products can be added</span>
                    </div>
                    <div class="pos-frame-adder__search" x-data="{ open: false }" @click.outside="open = false">
                        <input
                            type="text"
                            class="pos-frame-adder__input"
                            placeholder="Search frames by name or batch no…"
                            wire:model.live.debounce.350ms="frameSearchTerm"
                            @focus="open = true"
                            @input="open = true"
                        >
                        @if(!empty($frameSearchResults))
                        <div class="pos-frame-adder__dropdown" x-show="open">
                            @foreach($frameSearchResults as $fr)
                            <button type="button"
                                class="pos-frame-adder__option"
                                wire:click="addFrameProduct({{ $fr['id'] }})"
                                @click="open = false">
                                <span class="pos-frame-adder__option-name">
                                    {{ $fr['name'] }}
                                    @if($fr['batch'])
                                        <span class="pos-frame-adder__batch">{{ $fr['batch'] }}</span>
                                    @endif
                                </span>
                                <span class="pos-frame-adder__option-meta">
                                    {{ currency() }} {{ number_format($fr['price'], 2) }}
                                    &nbsp;·&nbsp;
                                    <span class="{{ $fr['stock'] <= 3 ? 'text-warning' : '' }}">{{ $fr['stock'] }} in stock</span>
                                </span>
                            </button>
                            @endforeach
                        </div>
                        @endif
                        @if(strlen($frameSearchTerm) >= 2 && empty($frameSearchResults))
                        <div class="pos-frame-adder__dropdown" x-show="open">
                            <div class="pos-frame-adder__empty">No frames found matching "{{ $frameSearchTerm }}"</div>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>

            @if(count($cart) > 0)

                {{-- Order Summary --}}
                <div class="pos-co-section pos-summary-section">

                    {{-- Subtotal / discount rows --}}
                    <div class="pos-sum-row pos-sum-row--sub">
                        <span>Subtotal</span>
                        <span>{{ currency() }} {{ number_format($totalAmount, 2) }}</span>
                    </div>

                    {{-- Discount --}}
                    <div class="pos-discount-wrap" @if($pendingDiscountApprovalId && !$discountApproved) wire:poll.15s="checkDiscountApprovalStatus" @endif>
                        <div class="pos-discount-row">
                            <div class="pos-dtype">
                                <button class="{{ $discountType === 'percentage' ? 'active' : '' }}"
                                        wire:click="$set('discountType', 'percentage')"
                                        {{ $discountApproved ? 'disabled' : '' }}>%</button>
                                <button class="{{ $discountType === 'fixed' ? 'active' : '' }}"
                                        wire:click="$set('discountType', 'fixed')"
                                        {{ $discountApproved ? 'disabled' : '' }}>{{ currency() }}</button>
                            </div>
                            <input class="pos-disc-input"
                                   type="number"
                                   wire:model.live.debounce.500ms="discountValue"
                                   placeholder="{{ $discountType === 'percentage' ? '0%' : '0.00' }}"
                                   min="0"
                                   step="{{ $discountType === 'percentage' ? '1' : '0.01' }}"
                                   max="{{ $discountType === 'percentage' ? '100' : $totalAmount }}"
                                   {{ $discountApproved ? 'readonly' : '' }}>
                            @if($discountAmount > 0 && !$discountApproved)
                                <button class="pos-approve-btn"
                                        wire:click="requestDiscountApproval"
                                        {{ $pendingDiscountApprovalId && $pendingDiscountApprovalStatus === 'pending' ? 'disabled' : '' }}>
                                    @if($pendingDiscountApprovalId && $pendingDiscountApprovalStatus === 'pending')
                                        <i class="fas fa-clock me-1"></i>Pending
                                    @else
                                        <i class="fas fa-paper-plane me-1"></i>Approve
                                    @endif
                                </button>
                            @endif
                            @if($discountValue > 0)
                                <button class="pos-icon-btn pos-icon-btn--ghost" wire:click="removeDiscount" title="Remove discount">
                                    <i class="fas fa-times"></i>
                                </button>
                            @endif
                        </div>
                        @if($discountAmount > 0)
                            <div class="pos-disc-status {{ $discountApproved ? 'pos-disc-status--ok' : 'pos-disc-status--pending' }}">
                                @if($discountApproved)
                                    <i class="fas fa-check-circle"></i> Approved by {{ $discountApprovedBy }}
                                    <span class="ms-auto">−{{ currency() }} {{ number_format($discountAmount, 2) }}</span>
                                @elseif($pendingDiscountApprovalId && $pendingDiscountApprovalStatus === 'pending')
                                    <i class="fas fa-clock"></i> Sent to Manager/Super Admin
                                    <button type="button" class="pos-disc-remove" wire:click="removeDiscount">
                                        Sell without discount
                                    </button>
                                    <span class="ms-auto">−{{ currency() }} {{ number_format($discountAmount, 2) }}</span>
                                @else
                                    <i class="fas fa-lock"></i> Manager approval required
                                    <button type="button" class="pos-disc-remove" wire:click="removeDiscount">
                                        Remove discount
                                    </button>
                                    <span class="ms-auto">−{{ currency() }} {{ number_format($discountAmount, 2) }}</span>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Part payment --}}
                    @if($hasFramesOrLenses)
                        <div class="pos-part-toggle {{ $isPartPayment ? 'pos-part-toggle--active' : '' }} {{ !$patientId ? 'pos-part-toggle--disabled' : '' }}">
                            <div>
                                <i class="fas fa-clock"></i>
                                <span>Part Payment / Hold Order</span>
                                @if(!$patientId)
                                    <small>Registered patient required</small>
                                @endif
                            </div>
                            <label class="pos-switch">
                                <input type="checkbox" wire:model.live="isPartPayment" {{ !$patientId ? 'disabled' : '' }}>
                                <span class="pos-switch__track"></span>
                            </label>
                        </div>
                    @endif

                    {{-- Total --}}
                    <div class="pos-sum-row pos-sum-row--total">
                        <span>Total</span>
                        <span>{{ currency() }} {{ number_format($finalAmount, 2) }}</span>
                    </div>

                </div>

                {{-- Payment --}}
                <div class="pos-co-section pos-payment-section">
                    <div class="pos-co-label"><i class="fas fa-credit-card"></i> Payment</div>

                    @php
                        $methods = ['cash' => 'Cash', 'card' => 'Card', 'momo' => 'MOMO', 'code' => 'CODE'];
                    @endphp

                    {{-- Method selector + amount --}}
                    <div class="pos-pay-add">
                        <div class="pos-pay-methods">
                            @foreach($methods as $m => $ml)
                                <button class="pos-pay-method {{ $newPaymentMethod === $m ? 'pos-pay-method--active' : '' }}"
                                        wire:click="selectNewPaymentMethod('{{ $m }}')">
                                    {{ $ml }}
                                </button>
                            @endforeach
                        </div>
                        <div class="pos-pay-amount-row">
                            <input class="pos-co-input pos-pay-input"
                                   type="number"
                                   wire:model.live="newPaymentAmount"
                                   placeholder="Amount"
                                   step="0.01"
                                   min="0.01">
                            <button class="pos-btn pos-btn--add"
                                    wire:click="addPayment"
                                    {{ (float)($newPaymentAmount ?? 0) <= 0 ? 'disabled' : '' }}>
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                    </div>

                    {{-- Payments list --}}
                    @if(count($payments) > 0)
                        <div class="pos-pay-list">
                            @foreach($payments as $i => $payment)
                                <div class="pos-pay-entry">
                                    <span class="pos-pay-entry__method">{{ strtoupper($payment['method']) }}</span>
                                    <span class="pos-pay-entry__amount">{{ currency() }} {{ number_format($payment['amount'], 2) }}</span>
                                    <button class="pos-icon-btn pos-icon-btn--ghost"
                                            wire:click="removePayment({{ $i }})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            @endforeach
                            <div class="pos-pay-subtotal">
                                <span>Total Paid</span>
                                <span class="{{ $totalPaid >= $finalAmount ? 'pos-paid--ok' : 'pos-paid--short' }}">
                                    {{ currency() }} {{ number_format($totalPaid, 2) }}
                                </span>
                            </div>
                        </div>

                        @if($change > 0)
                            <div class="pos-change-pill pos-change-pill--change">
                                <i class="fas fa-coins"></i>
                                <span>Change</span>
                                <span class="ms-auto">{{ currency() }} {{ number_format($change, 2) }}</span>
                            </div>
                        @elseif($totalPaid > 0 && $totalPaid < $finalAmount)
                            @php $remaining = round($finalAmount - $totalPaid, 2); @endphp
                            <div class="pos-change-pill pos-change-pill--balance">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>{{ $isPartPayment ? 'Balance (Held)' : 'Still Needed' }}</span>
                                <span class="ms-auto">{{ currency() }} {{ number_format($remaining, 2) }}</span>
                            </div>
                        @endif

                        @if($isPartPayment && count($payments) > 0 && $totalPaid < $finalAmount)
                            <p class="pos-part-note">
                                <i class="fas fa-info-circle me-1"></i>
                                Deposit {{ currency() }} {{ number_format($totalPaid, 2) }} recorded. Balance {{ currency() }} {{ number_format($finalAmount - $totalPaid, 2) }} due on pickup.
                            </p>
                        @endif
                    @else
                        <p class="pos-pay-hint">Select a method above and enter an amount.</p>
                    @endif
                </div>

                {{-- Checkout CTA --}}
                <div class="pos-co-action">
                    <button class="pos-cta-btn
                            {{ $discountBlocked ? 'pos-cta-btn--blocked' : ($isPartPayment ? 'pos-cta-btn--partial' : 'pos-cta-btn--ready') }}"
                            wire:click="initiateCheckout"
                            wire:loading.attr="disabled"
                            {{ $canCheckout ? '' : 'disabled' }}>
                        <span wire:loading.remove wire:target="initiateCheckout">
                            @if($discountBlocked)
                                <i class="fas fa-lock me-2"></i>Awaiting Discount Approval
                            @elseif($isPartPayment)
                                <i class="fas fa-clock me-2"></i>Hold Order — Part Payment
                            @else
                                <i class="fas fa-check-circle me-2"></i>Complete Sale
                            @endif
                        </span>
                        <span wire:loading wire:target="initiateCheckout">
                            <i class="fas fa-spinner fa-spin me-2"></i>Processing…
                        </span>
                    </button>
                    @if($discountBlocked)
                        <button type="button" class="pos-secondary-cta" wire:click="confirmSellWithoutPendingDiscount">
                            <i class="fas fa-tag me-1"></i>Sell at full price
                        </button>
                    @endif
                </div>

            @endif

        </div>
        {{-- / checkout --}}

    </div>
    {{-- / body --}}

</div>
{{-- / pos-shell --}}


{{-- ===================================================================
     PENDING CARTS MODAL
     =================================================================== --}}
<div x-data="{
        showPendingCartsModal: false,
        pendingCarts: [],
        expandedCart: null,
        totalCarts: 0,
        currentUserId: null
    }"
    @show-pending-carts.window="
        showPendingCartsModal = true;
        pendingCarts = $event.detail.carts;
        totalCarts = $event.detail.totalCarts;
        currentUserId = $event.detail.currentUserId;
        expandedCart = null;
    "
    @close-pending-carts-modal.window="showPendingCartsModal = false">

    <div x-show="showPendingCartsModal"
         x-cloak
         style="display:block; background:rgba(0,0,0,0.5); z-index:10500; position:fixed; top:0; left:0; width:100%; height:100%; overflow-y:auto;"
         @click.self="showPendingCartsModal = false; $dispatch('close-pending-carts-modal')">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="position:relative; z-index:10501; pointer-events:auto;">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-shopping-cart me-2"></i>Doctor Prescription Carts
                        <span class="badge bg-white text-info ms-2" x-text="totalCarts"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white"
                            @click="showPendingCartsModal = false; $dispatch('close-pending-carts-modal')"></button>
                </div>
                <div class="modal-body" style="max-height:70vh; overflow-y:auto;">
                    <template x-if="pendingCarts.length === 0">
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No Doctor Prescription Carts</h5>
                        </div>
                    </template>
                    <template x-if="pendingCarts.length > 0">
                        <div class="accordion" id="pendingCartsAccordion">
                            <template x-for="(cart, index) in pendingCarts" :key="cart.patient_id">
                                <div class="card mb-2 shadow-sm"
                                     :class="cart.is_mine ? 'border-primary' : 'border-secondary'">
                                    <div class="card-header"
                                         :class="expandedCart === index ? (cart.is_mine ? 'bg-primary text-white' : 'bg-secondary text-white') : 'bg-light'">
                                        <div class="row align-items-center">
                                            <div class="col-md-3">
                                                <h6 class="mb-0" :class="expandedCart === index ? 'text-white' : 'text-dark'">
                                                    <i class="fas fa-user-circle me-2"></i>
                                                    <span x-text="cart.patient_name"></span>
                                                    <template x-if="cart.is_mine">
                                                        <span class="badge badge-sm bg-success ms-1">Mine</span>
                                                    </template>
                                                </h6>
                                                <small :class="expandedCart === index ? 'text-white-50' : 'text-muted'">
                                                    <i class="fas fa-id-card me-1"></i><span x-text="cart.patient_number"></span>
                                                    <span class="d-block">Consultation #<span x-text="cart.consultation_id"></span></span>
                                                </small>
                                            </div>
                                            <div class="col-md-2">
                                                <div :class="expandedCart === index ? 'text-white' : 'text-muted'">
                                                    <i class="fas fa-shopping-basket me-1"></i><span x-text="cart.item_count"></span> items
                                                    <small class="d-block">Qty: <span x-text="cart.total_quantity"></span></small>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div :class="expandedCart === index ? 'text-white' : 'text-muted'">
                                                    <i class="fas fa-user-tie me-1"></i>
                                                    <small class="d-block" x-text="cart.cashier_name"></small>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <small :class="expandedCart === index ? 'text-white-50' : 'text-muted'">
                                                    <i class="fas fa-clock me-1"></i><span x-text="cart.created_at_human"></span>
                                                </small>
                                            </div>
                                            <div class="col-md-1 text-center">
                                                <h5 class="mb-0" :class="expandedCart === index ? 'text-white' : 'text-success'">
                                                    <span x-text="'{{ currency() }} ' + cart.total_amount.toFixed(2)"></span>
                                                </h5>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <button class="btn btn-sm btn-success me-1"
                                                        @click="$wire.loadCartFromList(cart.patient_id, cart.consultation_id)">
                                                    <i class="fas fa-download"></i> Load to Cart
                                                </button>
                                                <button class="btn btn-sm"
                                                        :class="expandedCart === index ? 'btn-light' : 'btn-outline-primary'"
                                                        @click="expandedCart = expandedCart === index ? null : index">
                                                    <i class="fas" :class="expandedCart === index ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                                </button>
                                                <template x-if="cart.is_mine">
                                                    <button class="btn btn-sm btn-outline-danger ms-1"
                                                            @click="if(confirm('Delete this cart?')) { $wire.deletePendingCart(cart.patient_id, cart.cashier_id) }">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </template>
                                                <template x-if="!cart.is_mine">
                                                    <button class="btn btn-sm btn-outline-secondary ms-1" disabled>
                                                        <i class="fas fa-lock"></i>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                    <div x-show="expandedCart === index" x-collapse class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr><th width="5%">#</th><th width="30%">Product</th><th width="10%" class="text-center">Qty</th><th width="15%">Frequency</th><th width="10%">Eye</th><th width="15%" class="text-end">Price</th><th width="15%" class="text-end">Total</th></tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="(item, itemIndex) in cart.items" :key="itemIndex">
                                                        <tr>
                                                            <td class="text-center"><span class="badge badge-secondary" x-text="itemIndex + 1"></span></td>
                                                            <td><strong x-text="item.product_name"></strong></td>
                                                            <td class="text-center"><span class="badge badge-info" x-text="item.quantity"></span></td>
                                                            <td>
                                                                <template x-if="item.frequency"><span class="badge badge-primary" x-text="item.frequency"></span></template>
                                                                <template x-if="!item.frequency"><small class="text-muted">—</small></template>
                                                            </td>
                                                            <td>
                                                                <template x-if="item.eye"><span class="badge" :class="{ 'badge-primary': item.eye === 'OD', 'badge-info': item.eye === 'OS', 'badge-success': item.eye === 'OU' }" x-text="item.eye"></span></template>
                                                                <template x-if="!item.eye"><small class="text-muted">—</small></template>
                                                            </td>
                                                            <td class="text-end"><span x-text="'{{ currency() }} ' + item.price.toFixed(2)"></span></td>
                                                            <td class="text-end"><strong x-text="'{{ currency() }} ' + item.total.toFixed(2)"></strong></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                                <tfoot class="table-light">
                                                    <tr>
                                                        <th colspan="6" class="text-end">Cart Total:</th>
                                                        <th class="text-end"><h5 class="mb-0 text-success"><span x-text="'{{ currency() }} ' + cart.total_amount.toFixed(2)"></span></h5></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="modal-footer">
                    <span class="badge bg-primary me-auto"><i class="fas fa-info-circle me-1"></i>Your carts are highlighted</span>
                    <button type="button" class="btn btn-secondary"
                            @click="showPendingCartsModal = false; $dispatch('close-pending-carts-modal')">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


{{-- ===================================================================
     APPROVED DISCOUNTS MODAL
     =================================================================== --}}
<div x-data="{
        showApprovedDiscountsModal: false,
        approvedDiscounts: [],
        expandedDiscount: null,
        totalDiscounts: 0
    }"
    @show-approved-discounts.window="
        showApprovedDiscountsModal = true;
        approvedDiscounts = $event.detail.discounts;
        totalDiscounts = $event.detail.totalDiscounts;
        expandedDiscount = null;
    "
    @close-approved-discounts-modal.window="showApprovedDiscountsModal = false">

    <div x-show="showApprovedDiscountsModal"
         x-cloak
         style="display:block; background:rgba(0,0,0,0.5); z-index:10500; position:fixed; top:0; left:0; width:100%; height:100%; overflow-y:auto;"
         @click.self="showApprovedDiscountsModal = false; $dispatch('close-approved-discounts-modal')">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="position:relative; z-index:10501; pointer-events:auto;">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-tags me-2"></i>Approved Discounts
                        <span class="badge bg-white text-success ms-2" x-text="totalDiscounts"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white"
                            @click="showApprovedDiscountsModal = false; $dispatch('close-approved-discounts-modal')"></button>
                </div>
                <div class="modal-body" style="max-height:70vh; overflow-y:auto;">
                    <template x-if="approvedDiscounts.length === 0">
                        <div class="text-center py-5">
                            <i class="fas fa-tags fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No Approved Discounts</h5>
                        </div>
                    </template>
                    <template x-if="approvedDiscounts.length > 0">
                        <div class="accordion" id="approvedDiscountsAccordion">
                            <template x-for="(discount, index) in approvedDiscounts" :key="discount.id">
                                <div class="card mb-2 shadow-sm border-success">
                                    <div class="card-header" :class="expandedDiscount === index ? 'bg-success text-white' : 'bg-light'">
                                        <div class="row align-items-center">
                                            <div class="col-md-3">
                                                <h6 class="mb-0" :class="expandedDiscount === index ? 'text-white' : 'text-dark'">
                                                    <i class="fas fa-user-circle me-2"></i>
                                                    <span x-text="discount.patient_name"></span>
                                                </h6>
                                                <small :class="expandedDiscount === index ? 'text-white-50' : 'text-muted'">
                                                    <i class="fas fa-id-card me-1"></i><span x-text="discount.patient_number"></span>
                                                </small>
                                            </div>
                                            <div class="col-md-2">
                                                <small :class="expandedDiscount === index ? 'text-white-50' : 'text-muted'">Approved by</small>
                                                <div :class="expandedDiscount === index ? 'text-white' : 'text-dark'" x-text="discount.approver_name"></div>
                                            </div>
                                            <div class="col-md-2">
                                                <small :class="expandedDiscount === index ? 'text-white-50' : 'text-muted'">Gross</small>
                                                <div :class="expandedDiscount === index ? 'text-white' : 'text-muted'" x-text="'{{ currency() }} ' + discount.gross_amount.toFixed(2)"></div>
                                            </div>
                                            <div class="col-md-2">
                                                <small :class="expandedDiscount === index ? 'text-white-50' : 'text-muted'">Discount</small>
                                                <h5 class="mb-0" :class="expandedDiscount === index ? 'text-white' : 'text-success'" x-text="'{{ currency() }} ' + discount.discount_amount.toFixed(2)"></h5>
                                            </div>
                                            <div class="col-md-1">
                                                <small :class="expandedDiscount === index ? 'text-white-50' : 'text-muted'" x-text="discount.approved_at_human"></small>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <button class="btn btn-sm btn-success me-1"
                                                        @click="$wire.applyApprovedDiscount(discount.id)">
                                                    <i class="fas fa-check"></i> Apply to Cart
                                                </button>
                                                <button class="btn btn-sm"
                                                        :class="expandedDiscount === index ? 'btn-light' : 'btn-outline-success'"
                                                        @click="expandedDiscount = expandedDiscount === index ? null : index">
                                                    <i class="fas" :class="expandedDiscount === index ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div x-show="expandedDiscount === index" x-collapse class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="(item, itemIndex) in discount.items" :key="itemIndex">
                                                        <tr>
                                                            <td><strong x-text="item.name"></strong></td>
                                                            <td class="text-center"><span class="badge badge-info" x-text="item.quantity"></span></td>
                                                            <td class="text-end"><strong x-text="'{{ currency() }} ' + item.total.toFixed(2)"></strong></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="modal-footer">
                    <span class="badge bg-success me-auto"><i class="fas fa-check-circle me-1"></i>Apply an approval before checkout</span>
                    <button type="button" class="btn btn-secondary"
                            @click="showApprovedDiscountsModal = false; $dispatch('close-approved-discounts-modal')">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


{{-- ===================================================================
     DISCOUNT APPROVAL MODAL
     =================================================================== --}}
@if($showApprovalModal)
<div class="pos-overlay" style="z-index:11000;">
    <div class="pos-approval-dialog">
        <div class="pos-approval-header">
            <span><i class="fas fa-user-shield me-2"></i>Manager Approval Required</span>
            <button wire:click="cancelApproval"><i class="fas fa-times"></i></button>
        </div>
        <div class="pos-approval-body">
            <div class="pos-approval-disc-info">
                <i class="fas fa-tag me-1"></i>
                <strong>Discount:</strong>
                {{ $discountType === 'percentage' ? number_format($discountValue, 0).'% off' : currency().' '.number_format($discountValue, 2).' off' }}
                &nbsp;&rarr;&nbsp;
                <strong>−{{ currency() }} {{ number_format($discountAmount, 2) }}</strong>
            </div>
            <p class="pos-approval-hint">A <strong>Manager</strong> or <strong>Super Admin</strong> must sign in to authorise this discount.</p>

            @if($approvalError)
                <div class="pos-approval-error">
                    <i class="fas fa-exclamation-circle me-1"></i>{{ $approvalError }}
                </div>
            @endif

            <div class="pos-field">
                <label>Manager / Super Admin Email</label>
                <div class="pos-input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" wire:model="approvalEmail" placeholder="manager@example.com" autocomplete="off">
                </div>
            </div>
            <div class="pos-field">
                <label>Password</label>
                <div class="pos-input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" wire:model="approvalPassword" placeholder="••••••••" autocomplete="new-password">
                </div>
            </div>

            <button class="pos-btn pos-btn--approve" wire:click="approveDiscount" wire:loading.attr="disabled" wire:target="approveDiscount">
                <span wire:loading.remove wire:target="approveDiscount"><i class="fas fa-check-circle me-2"></i>Approve Discount</span>
                <span wire:loading wire:target="approveDiscount"><i class="fas fa-spinner fa-spin me-2"></i>Verifying…</span>
            </button>
            <button class="pos-btn pos-btn--ghost pos-btn--full" wire:click="cancelApproval" style="margin-top:.5rem;">
                <i class="fas fa-times me-1"></i>Cancel
            </button>
        </div>
    </div>
</div>
@endif

</div>{{-- /x-data wrapper --}}

{{-- ===================================================================
     STYLES
     =================================================================== --}}
<style>
[x-cloak] { display: none !important; }

/* ---- Spacing shims for AdminLTE (Bootstrap 4 base) ---- */
.pos-shell .me-1 { margin-right:.25rem !important; }
.pos-shell .me-2 { margin-right:.5rem  !important; }
.pos-shell .ms-1 { margin-left:.25rem  !important; }
.pos-shell .ms-2 { margin-left:.5rem   !important; }
.pos-shell .ms-auto { margin-left:auto !important; }

/* ---- Shell / Layout ---- */
.pos-shell {
    background: #f0f2f6;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 60px);
    overflow: hidden;
}

.pos-body {
    display: flex;
    flex: 1;
    gap: 0;
    min-height: 0;
    overflow: hidden;
}

/* ---- Top Bar ---- */
.pos-topbar {
    align-items: center;
    background: #111827;
    border-bottom: 1px solid #1f2937;
    display: flex;
    flex-shrink: 0;
    gap: 1rem;
    justify-content: space-between;
    padding: .6rem 1.1rem;
}

.pos-topbar__brand {
    display: flex;
    flex-direction: column;
}

.pos-topbar__title {
    color: #f9fafb;
    font-size: .95rem;
    font-weight: 800;
    line-height: 1;
}

.pos-topbar__sub {
    color: #6b7280;
    font-size: .7rem;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.pos-topbar__actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
}

.pos-chip {
    align-items: center;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    color: #d1d5db;
    display: inline-flex;
    font-size: .75rem;
    font-weight: 600;
    gap: .4rem;
    padding: .3rem .6rem;
    white-space: nowrap;
}

.pos-topbtn {
    align-items: center;
    background: rgba(255,255,255,.09);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 6px;
    color: #e5e7eb;
    cursor: pointer;
    display: inline-flex;
    font-size: .78rem;
    font-weight: 700;
    gap: .35rem;
    padding: .3rem .7rem;
    transition: background .15s;
    white-space: nowrap;
}
.pos-topbtn:hover:not(:disabled) { background: rgba(255,255,255,.16); }
.pos-topbtn:disabled { opacity: .4; cursor: not-allowed; }
.pos-topbtn--danger { border-color: rgba(239,68,68,.35); color: #fca5a5; }
.pos-topbtn--danger:hover:not(:disabled) { background: rgba(239,68,68,.18); }
.pos-topbtn__badge {
    align-items: center;
    background: #f59e0b;
    border-radius: 999px;
    color: #111827;
    display: inline-flex;
    font-size: .65rem;
    font-weight: 900;
    height: 18px;
    justify-content: center;
    min-width: 18px;
    padding: 0 .35rem;
}
.pos-topbtn__badge--success {
    background: #22c55e;
    color: #052e16;
}

/* ---- Products panel ---- */
.pos-products {
    background: #f8fafc;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0;
    min-width: 0;
    overflow: hidden;
    padding: .85rem;
}

.pos-search-bar {
    align-items: center;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    display: flex;
    flex-shrink: 0;
    gap: .5rem;
    margin-bottom: .65rem;
    padding: .4rem .75rem;
}

.pos-search-icon { color: #94a3b8; font-size: .85rem; }

.pos-search-input {
    background: transparent;
    border: none;
    color: #0f172a;
    flex: 1;
    font-size: .875rem;
    outline: none;
}

.pos-search-count {
    background: #f1f5f9;
    border-radius: 999px;
    color: #64748b;
    font-size: .7rem;
    font-weight: 700;
    padding: .15rem .5rem;
    white-space: nowrap;
}

/* ---- Category pills ---- */
.pos-cats {
    display: flex;
    flex-shrink: 0;
    gap: .4rem;
    margin-bottom: .65rem;
    overflow-x: auto;
    padding-bottom: 2px;
}

.pos-cat {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    color: #475569;
    cursor: pointer;
    font-size: .78rem;
    font-weight: 700;
    padding: .3rem .8rem;
    transition: background .12s, color .12s, border-color .12s;
    white-space: nowrap;
}
.pos-cat:hover { background: #f1f5f9; }
.pos-cat--active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }

/* ---- Product grid ---- */
.pos-grid {
    display: grid;
    flex: 1;
    gap: .6rem;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    overflow-y: auto;
    padding: .1rem .15rem;
}

.pos-empty-state {
    align-items: center;
    color: #94a3b8;
    display: flex;
    flex-direction: column;
    grid-column: 1 / -1;
    justify-content: center;
    padding: 3rem 0;
}
.pos-empty-state i { font-size: 2.5rem; margin-bottom: .75rem; }
.pos-empty-state p { font-size: .875rem; margin: 0; }

.pos-product {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: .25rem;
    padding: .75rem;
    transition: border-color .13s, box-shadow .13s, transform .13s;
}
.pos-product:hover:not(.pos-product--oos) {
    border-color: #3b82f6;
    box-shadow: 0 4px 16px rgba(59,130,246,.15);
    transform: translateY(-2px);
}
.pos-product--oos { cursor: not-allowed; opacity: .5; }

.pos-product__icon {
    align-items: center;
    background: #eff6ff;
    border-radius: 8px;
    color: #2563eb;
    display: flex;
    font-size: .95rem;
    height: 34px;
    justify-content: center;
    margin-bottom: .15rem;
    width: 34px;
}

.pos-product__name {
    color: #0f172a;
    font-size: .78rem;
    font-weight: 700;
    line-height: 1.3;
    min-height: 2rem;
}

.pos-product__price {
    color: #1d4ed8;
    font-size: .85rem;
    font-weight: 800;
}

.pos-stock {
    border-radius: 999px;
    font-size: .65rem;
    font-weight: 800;
    padding: .1rem .45rem;
}
.pos-stock--ok  { background: #dcfce7; color: #15803d; }
.pos-stock--low { background: #fef9c3; color: #92400e; }
.pos-stock--out { background: #fee2e2; color: #b91c1c; }

.pos-pagination {
    flex-shrink: 0;
    margin-top: .65rem;
}

/* ---- Checkout panel ---- */
.pos-checkout {
    background: #1e293b;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    overflow-y: auto;
    width: clamp(440px, 34vw, 560px);
}

.pos-co-section {
    border-bottom: 1px solid rgba(255,255,255,.06);
    padding: .75rem .9rem;
}

.pos-co-label {
    align-items: center;
    color: #94a3b8;
    display: flex;
    font-size: .7rem;
    font-weight: 800;
    gap: .4rem;
    justify-content: space-between;
    letter-spacing: .07em;
    margin-bottom: .55rem;
    text-transform: uppercase;
}

.pos-badge {
    background: #334155;
    border-radius: 999px;
    color: #94a3b8;
    font-size: .7rem;
    font-weight: 700;
    padding: .1rem .45rem;
}

/* ---- Patient section ---- */
.pos-patient-section { flex-shrink: 0; }

.pos-mode-switch {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    display: grid;
    gap: 4px;
    grid-template-columns: 1fr 1fr;
    margin-bottom: .6rem;
    padding: 4px;
}
.pos-mode-btn {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    color: #94a3b8;
    cursor: pointer;
    font-size: .72rem;
    font-weight: 700;
    min-height: 34px;
    padding: .35rem .45rem;
}
.pos-mode-btn:hover { background: rgba(255,255,255,.06); color: #e2e8f0; }
.pos-mode-btn--active {
    background: rgba(59,130,246,.18);
    border-color: rgba(59,130,246,.45);
    color: #93c5fd;
}
.pos-mode-btn--direct {
    background: rgba(34,197,94,.14);
    border-color: rgba(34,197,94,.4);
    color: #86efac;
}
.pos-direct-panel {
    background: rgba(34,197,94,.07);
    border: 1px solid rgba(34,197,94,.22);
    border-radius: 8px;
    padding: .65rem;
}
.pos-direct-panel__status {
    align-items: center;
    color: #86efac;
    display: flex;
    font-size: .75rem;
    font-weight: 800;
    gap: .35rem;
    margin-bottom: .5rem;
}
.pos-direct-panel__hint { color: #64748b; font-size: .68rem; margin-top: .35rem; }
.pos-direct-panel__error { color: #fca5a5; font-size: .7rem; margin-top: .3rem; }

.pos-co-input {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 7px;
    color: #f1f5f9;
    font-size: .85rem;
    outline: none;
    padding: .45rem .7rem;
    transition: border-color .15s;
    width: 100%;
}
.pos-co-input::placeholder { color: #64748b; }
.pos-co-input:focus { border-color: #3b82f6; }

.pos-patient-drop {
    background: #1e293b;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 8px;
    box-shadow: 0 10px 28px rgba(0,0,0,.35);
    left: 0;
    max-height: 220px;
    overflow-y: auto;
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    z-index: 500;
}

.pos-patient-row {
    border-bottom: 1px solid rgba(255,255,255,.06);
    cursor: pointer;
    padding: .6rem .8rem;
    transition: background .12s;
}
.pos-patient-row:hover { background: rgba(255,255,255,.07); }
.pos-patient-row:last-child { border-bottom: none; }

.pos-patient-row__name { color: #f1f5f9; font-size: .85rem; font-weight: 700; }
.pos-patient-row__meta { color: #64748b; font-size: .75rem; margin-top: .1rem; }

.pos-patient-selected {
    align-items: center;
    background: rgba(34,197,94,.1);
    border: 1px solid rgba(34,197,94,.25);
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    padding: .55rem .75rem;
}
.pos-patient-selected__info { align-items: center; display: flex; gap: .6rem; }
.pos-patient-selected__icon { color: #22c55e; font-size: 1.1rem; }
.pos-patient-selected__name { color: #f1f5f9; font-size: .88rem; font-weight: 700; }
.pos-patient-selected__sub { color: #4ade80; font-size: .7rem; }

/* ---- Cart section ---- */
.pos-cart-section {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 280px;
    padding-bottom: 0;
}

.pos-cart-list {
    max-height: min(46vh, 460px);
    overflow-y: auto;
    padding: .15rem 0 .45rem;
}

.pos-cart-empty {
    align-items: center;
    color: #475569;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    justify-content: center;
    padding: 2rem .5rem;
    text-align: center;
}
.pos-cart-empty i { font-size: 2rem; opacity: .4; }
.pos-cart-empty p { font-size: .8rem; margin: 0; }

/* ── Prescription lock styles ── */
.pos-cart-item--locked {
    opacity: .88;
    border-left: 3px solid #64748b;
}
.pos-rx-badge {
    background: rgba(100,116,139,.22);
    border-radius: .25rem;
    color: #94a3b8;
    font-size: .6rem;
    font-weight: 700;
    letter-spacing: .04em;
    margin-right: .35rem;
    padding: .1rem .35rem;
    text-transform: uppercase;
    white-space: nowrap;
}
.pos-rx-badge--frame {
    background: rgba(16,185,129,.18);
    color: #6ee7b7;
}
.pos-lock-icon {
    color: #64748b;
    font-size: .75rem;
    margin-left: .25rem;
    opacity: .7;
}
.pos-qty-locked {
    color: #64748b;
    font-size: .72rem;
    padding: .2rem .5rem;
}

/* ── Frame adder panel ── */
.pos-frame-adder {
    border-top: 1px solid rgba(16,185,129,.25);
    margin-top: .5rem;
    padding: .6rem .75rem .75rem;
}
.pos-frame-adder__header {
    align-items: center;
    color: #6ee7b7;
    display: flex;
    font-size: .72rem;
    font-weight: 700;
    gap: .35rem;
    letter-spacing: .03em;
    margin-bottom: .45rem;
    text-transform: uppercase;
}
.pos-frame-adder__hint {
    color: #475569;
    font-size: .65rem;
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
}
.pos-frame-adder__search { position: relative; }
.pos-frame-adder__input {
    background: rgba(255,255,255,.06);
    border: 1.5px solid rgba(16,185,129,.3);
    border-radius: .5rem;
    color: #e2e8f0;
    font-size: .82rem;
    outline: none;
    padding: .45rem .7rem;
    transition: border-color .2s;
    width: 100%;
}
.pos-frame-adder__input:focus { border-color: #10b981; }
.pos-frame-adder__input::placeholder { color: #475569; }
.pos-frame-adder__dropdown {
    background: #1e293b;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: .5rem;
    box-shadow: 0 6px 18px rgba(0,0,0,.4);
    left: 0;
    max-height: 210px;
    overflow-y: auto;
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    z-index: 50;
}
.pos-frame-adder__option {
    background: transparent;
    border: none;
    border-bottom: 1px solid rgba(255,255,255,.05);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    padding: .5rem .75rem;
    text-align: left;
    transition: background .12s;
    width: 100%;
}
.pos-frame-adder__option:hover { background: rgba(16,185,129,.1); }
.pos-frame-adder__option:last-child { border-bottom: none; }
.pos-frame-adder__option-name { color: #e2e8f0; font-size: .8rem; }
.pos-frame-adder__batch { background: rgba(255,255,255,.1); border-radius: .2rem; color: #94a3b8; font-size: .65rem; margin-left: .35rem; padding: .05rem .3rem; }
.pos-frame-adder__option-meta { color: #64748b; font-size: .72rem; white-space: nowrap; }
.pos-frame-adder__empty { color: #64748b; font-size: .78rem; padding: .75rem; text-align: center; }

/* Light-mode overrides for new elements */
.pos-shell--light .pos-cart-item--locked { border-left-color: #94a3b8; }
.pos-shell--light .pos-rx-badge { background: #e2e8f0; color: #64748b; }
.pos-shell--light .pos-rx-badge--frame { background: #d1fae5; color: #059669; }
.pos-shell--light .pos-lock-icon { color: #94a3b8; }
.pos-shell--light .pos-qty-locked { color: #94a3b8; }
.pos-shell--light .pos-frame-adder { border-top-color: rgba(16,185,129,.3); }
.pos-shell--light .pos-frame-adder__header { color: #059669; }
.pos-shell--light .pos-frame-adder__hint { color: #94a3b8; }
.pos-shell--light .pos-frame-adder__input {
    background: #fff;
    border-color: rgba(16,185,129,.4);
    color: #0f172a;
}
.pos-shell--light .pos-frame-adder__input::placeholder { color: #94a3b8; }
.pos-shell--light .pos-frame-adder__dropdown { background: #fff; border-color: #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.pos-shell--light .pos-frame-adder__option { border-bottom-color: #f1f5f9; }
.pos-shell--light .pos-frame-adder__option:hover { background: #f0fdf4; }
.pos-shell--light .pos-frame-adder__option-name { color: #0f172a; }
.pos-shell--light .pos-frame-adder__batch { background: #e2e8f0; color: #64748b; }
.pos-shell--light .pos-frame-adder__option-meta { color: #64748b; }
.pos-shell--light .pos-frame-adder__empty { color: #94a3b8; }

.pos-cart-item {
    background: rgba(255,255,255,.04);
    border-bottom: 1px solid rgba(255,255,255,.05);
    padding: .75rem 1rem;
    transition: background .12s;
}
.pos-cart-item:hover { background: rgba(255,255,255,.07); }

.pos-cart-item__row1 {
    align-items: center;
    display: flex;
    gap: .4rem;
    margin-bottom: .4rem;
}

.pos-cart-item__name {
    color: #f1f5f9;
    flex: 1;
    font-size: .84rem;
    font-weight: 700;
    line-height: 1.2;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-cart-item__total {
    color: #34d399;
    font-size: .88rem;
    font-weight: 800;
    white-space: nowrap;
}

.pos-remove-btn { margin-left: .15rem; }

.pos-cart-item__row2 {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}

.pos-cart-item__price { color: #64748b; font-size: .72rem; }

/* Qty control */
.pos-qty {
    align-items: center;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    display: inline-flex;
    overflow: hidden;
}
.pos-qty button {
    background: transparent;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    font-size: .85rem;
    font-weight: 700;
    height: 28px;
    line-height: 1;
    padding: 0 .45rem;
    transition: background .12s, color .12s;
}
.pos-qty button:hover { background: rgba(255,255,255,.1); color: #f1f5f9; }
.pos-qty input {
    background: transparent;
    border: none;
    color: #f1f5f9;
    font-size: .78rem;
    font-weight: 700;
    height: 28px;
    outline: none;
    padding: 0;
    text-align: center;
    width: 34px;
}
.pos-qty input::-webkit-outer-spin-button,
.pos-qty input::-webkit-inner-spin-button { -webkit-appearance: none; }

/* Select */
.pos-select {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    color: #cbd5e1;
    font-size: .72rem;
    height: 28px;
    min-width: 102px;
    outline: none;
    padding: 0 .35rem;
    transition: border-color .12s;
}
.pos-select:focus { border-color: #3b82f6; }
.pos-select option { background: #1e293b; }

/* ---- Icon buttons ---- */
.pos-icon-btn {
    align-items: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 5px;
    cursor: pointer;
    display: inline-flex;
    font-size: .8rem;
    height: 26px;
    justify-content: center;
    padding: 0;
    transition: background .12s, border-color .12s, color .12s;
    width: 26px;
}
.pos-icon-btn--ghost { color: #475569; }
.pos-icon-btn--ghost:hover { background: rgba(255,255,255,.08); color: #94a3b8; }
.pos-icon-btn--danger { color: #f87171; }
.pos-icon-btn--danger:hover { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.25); }

/* ---- Summary section ---- */
.pos-summary-section { flex-shrink: 0; }

.pos-sum-row {
    align-items: center;
    display: flex;
    justify-content: space-between;
    margin-bottom: .5rem;
}
.pos-sum-row--sub { color: #64748b; font-size: .83rem; }
.pos-sum-row--total {
    border-top: 1px solid rgba(255,255,255,.1);
    color: #f1f5f9;
    font-size: 1.05rem;
    font-weight: 800;
    margin-bottom: 0;
    margin-top: .4rem;
    padding-top: .5rem;
}
.pos-sum-row--total span:last-child { color: #34d399; }

/* Discount */
.pos-discount-wrap { margin-bottom: .5rem; }

.pos-discount-row {
    align-items: center;
    display: flex;
    gap: .4rem;
    margin-bottom: .35rem;
}

.pos-dtype {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    display: inline-flex;
    flex-shrink: 0;
    overflow: hidden;
}
.pos-dtype button {
    background: transparent;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    font-size: .8rem;
    font-weight: 800;
    height: 32px;
    padding: 0 .6rem;
    transition: background .12s, color .12s;
}
.pos-dtype button.active { background: #f59e0b; color: #fff; }
.pos-dtype button:disabled { opacity: .5; cursor: not-allowed; }

.pos-disc-input {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    color: #f1f5f9;
    flex: 1;
    font-size: .85rem;
    height: 32px;
    min-width: 0;
    outline: none;
    padding: 0 .6rem;
}
.pos-disc-input:focus { border-color: #f59e0b; }
.pos-disc-input[readonly] { opacity: .6; }

.pos-approve-btn {
    background: #f59e0b;
    border: none;
    border-radius: 6px;
    color: #fff;
    cursor: pointer;
    font-size: .78rem;
    font-weight: 800;
    height: 32px;
    padding: 0 .7rem;
    white-space: nowrap;
}
.pos-approve-btn:hover { background: #d97706; }

.pos-disc-status {
    align-items: center;
    border-radius: 6px;
    display: flex;
    font-size: .75rem;
    font-weight: 700;
    gap: .35rem;
    padding: .35rem .6rem;
}
.pos-disc-status--ok      { background: rgba(34,197,94,.12); color: #4ade80; }
.pos-disc-status--pending { background: rgba(245,158,11,.1);  color: #fbbf24; }
.pos-disc-remove {
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(245,158,11,.35);
    border-radius: 5px;
    color: inherit;
    cursor: pointer;
    font-size: .7rem;
    font-weight: 800;
    margin-left: .35rem;
    padding: .15rem .45rem;
}
.pos-disc-remove:hover { background: rgba(245,158,11,.18); }

/* Part payment toggle */
.pos-part-toggle {
    align-items: center;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 7px;
    color: #64748b;
    display: flex;
    font-size: .78rem;
    font-weight: 600;
    gap: .45rem;
    justify-content: space-between;
    margin-top: .5rem;
    padding: .45rem .6rem;
    transition: border-color .15s, background .15s;
}
.pos-part-toggle--active { background: rgba(251,191,36,.08); border-color: rgba(251,191,36,.25); color: #fbbf24; }
.pos-part-toggle--disabled { opacity: .58; }
.pos-part-toggle small {
    display: block;
    font-size: .62rem;
    font-weight: 500;
    margin-left: 1.15rem;
    margin-top: .1rem;
}

.pos-switch { display: inline-flex; position: relative; }
.pos-switch input { height: 0; opacity: 0; position: absolute; width: 0; }
.pos-switch__track {
    background: #334155;
    border-radius: 999px;
    cursor: pointer;
    display: block;
    height: 20px;
    position: relative;
    transition: background .15s;
    width: 36px;
}
.pos-switch__track::after {
    background: #fff;
    border-radius: 50%;
    content: '';
    height: 14px;
    left: 3px;
    position: absolute;
    top: 3px;
    transition: transform .15s;
    width: 14px;
}
.pos-switch input:checked ~ .pos-switch__track { background: #f59e0b; }
.pos-switch input:checked ~ .pos-switch__track::after { transform: translateX(16px); }

/* ---- Payment section ---- */
.pos-payment-section { flex-shrink: 0; }

.pos-pay-add { display: flex; flex-direction: column; gap: .45rem; margin-bottom: .55rem; }

.pos-pay-methods { display: flex; gap: .35rem; }

.pos-pay-method {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 7px;
    color: #94a3b8;
    cursor: pointer;
    flex: 1;
    font-size: .78rem;
    font-weight: 800;
    padding: .45rem .2rem;
    text-align: center;
    transition: background .12s, border-color .12s, color .12s;
}
.pos-pay-method:hover { background: rgba(255,255,255,.1); color: #f1f5f9; }
.pos-pay-method--active { background: #1d4ed8; border-color: #2563eb; color: #fff; }

.pos-pay-amount-row { display: flex; gap: .4rem; }
.pos-pay-input { flex: 1; }

.pos-btn--add {
    background: #16a34a;
    border: none;
    border-radius: 7px;
    color: #fff;
    cursor: pointer;
    font-size: .82rem;
    font-weight: 700;
    padding: 0 .8rem;
    white-space: nowrap;
}
.pos-btn--add:hover:not(:disabled) { background: #15803d; }
.pos-btn--add:disabled { opacity: .45; cursor: not-allowed; }

.pos-pay-list {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 8px;
    margin-bottom: .5rem;
    overflow: hidden;
}

.pos-pay-entry {
    align-items: center;
    border-bottom: 1px solid rgba(255,255,255,.05);
    display: flex;
    gap: .5rem;
    padding: .45rem .65rem;
}
.pos-pay-entry:last-child { border-bottom: none; }

.pos-pay-entry__method {
    background: rgba(59,130,246,.15);
    border-radius: 5px;
    color: #93c5fd;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .04em;
    padding: .1rem .4rem;
}

.pos-pay-entry__amount { color: #34d399; flex: 1; font-size: .85rem; font-weight: 700; text-align: right; }

.pos-pay-subtotal {
    align-items: center;
    background: rgba(255,255,255,.05);
    border-top: 1px solid rgba(255,255,255,.08);
    color: #f1f5f9;
    display: flex;
    font-size: .82rem;
    font-weight: 700;
    justify-content: space-between;
    padding: .45rem .65rem;
}
.pos-paid--ok    { color: #34d399; }
.pos-paid--short { color: #f87171; }

.pos-change-pill {
    align-items: center;
    border-radius: 7px;
    display: flex;
    font-size: .8rem;
    font-weight: 700;
    gap: .4rem;
    margin-bottom: .4rem;
    padding: .4rem .65rem;
}
.pos-change-pill--change  { background: rgba(34,197,94,.1); color: #4ade80; }
.pos-change-pill--balance { background: rgba(239,68,68,.1);  color: #f87171; }

.pos-part-note, .pos-pay-hint {
    color: #475569;
    font-size: .72rem;
    line-height: 1.4;
    margin: 0;
    padding: .2rem 0;
}

/* ---- Checkout CTA ---- */
.pos-co-action {
    flex-shrink: 0;
    padding: .75rem .9rem;
}

.pos-cta-btn {
    border: none;
    border-radius: 9px;
    cursor: pointer;
    font-size: .95rem;
    font-weight: 800;
    min-height: 50px;
    padding: .6rem 1rem;
    transition: opacity .15s, transform .1s;
    width: 100%;
}
.pos-cta-btn:disabled { cursor: not-allowed; opacity: .45; transform: none; }
.pos-cta-btn:not(:disabled):hover { opacity: .9; transform: translateY(-1px); }
.pos-cta-btn--ready   { background: #16a34a; color: #fff; }
.pos-cta-btn--partial { background: #d97706; color: #fff; }
.pos-cta-btn--blocked { background: #374151; color: #9ca3af; }
.pos-secondary-cta {
    background: transparent;
    border: 1px solid rgba(245,158,11,.45);
    border-radius: 8px;
    color: #fbbf24;
    cursor: pointer;
    font-size: .82rem;
    font-weight: 800;
    margin-top: .45rem;
    min-height: 38px;
    width: 100%;
}
.pos-secondary-cta:hover { background: rgba(245,158,11,.12); }

/* ---- Receipt dialog ---- */
.pos-overlay {
    align-items: center;
    background: rgba(0,0,0,.65);
    bottom: 0;
    display: flex;
    justify-content: center;
    left: 0;
    position: fixed;
    right: 0;
    top: 0;
}

.pos-receipt-dialog {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    display: flex;
    flex-direction: column;
    max-height: 90vh;
    max-width: 380px;
    overflow: hidden;
    width: 100%;
}

.pos-receipt-header {
    align-items: center;
    background: #16a34a;
    color: #fff;
    display: flex;
    font-size: .88rem;
    font-weight: 700;
    justify-content: space-between;
    padding: .7rem 1rem;
}
.pos-receipt-header button {
    background: transparent;
    border: none;
    color: #fff;
    cursor: pointer;
    font-size: .9rem;
    opacity: .8;
}
.pos-receipt-header button:hover { opacity: 1; }

.pos-receipt-body {
    background: #e9ecef;
    flex: 1;
    overflow-y: auto;
    padding: .75rem;
}

.pos-receipt-paper {
    background: #fff;
    border: 1px solid #ccc;
    box-shadow: 0 2px 8px rgba(0,0,0,.1);
    font-family: 'Courier New', monospace;
    font-size: 11px;
    line-height: 1.6;
    margin: 0 auto;
    max-width: 300px;
    padding: 12px 14px;
}

.pos-receipt-footer {
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: .5rem;
    padding: .65rem .9rem;
}

/* ---- General buttons ---- */
.pos-btn {
    border-radius: 7px;
    cursor: pointer;
    font-size: .85rem;
    font-weight: 700;
    padding: .5rem .9rem;
    transition: opacity .12s;
}
.pos-btn:disabled { opacity: .5; cursor: not-allowed; }
.pos-btn--success { background: #16a34a; border: none; color: #fff; flex: 1; }
.pos-btn--success:hover:not(:disabled) { background: #15803d; }
.pos-btn--pdf { background: #dc2626; border: none; color: #fff; flex: 1; text-align: center; }
.pos-btn--pdf:hover { background: #b91c1c; color: #fff; text-decoration: none; }
.pos-btn--ghost { background: #fff; border: 1px solid #d1d5db; color: #374151; }
.pos-btn--ghost:hover:not(:disabled) { background: #f9fafb; }
.pos-btn--lg { min-height: 42px; }
.pos-btn--full { display: block; text-align: center; width: 100%; }
.pos-btn--approve {
    background: #f59e0b;
    border: none;
    border-radius: 7px;
    color: #fff;
    cursor: pointer;
    display: block;
    font-size: .88rem;
    font-weight: 800;
    min-height: 44px;
    width: 100%;
}
.pos-btn--approve:hover:not(:disabled) { background: #d97706; }

/* ---- Approval dialog ---- */
.pos-approval-dialog {
    background: #fff;
    border-radius: 12px;
    border-top: 4px solid #f59e0b;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    max-width: 420px;
    width: 100%;
}

.pos-approval-header {
    align-items: center;
    background: #fef9c3;
    display: flex;
    font-size: .9rem;
    font-weight: 800;
    justify-content: space-between;
    padding: .75rem 1rem;
}
.pos-approval-header button {
    background: transparent;
    border: none;
    color: #374151;
    cursor: pointer;
    font-size: .9rem;
}

.pos-approval-body { padding: 1rem; }

.pos-approval-disc-info {
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 7px;
    color: #78350f;
    font-size: .85rem;
    margin-bottom: .75rem;
    padding: .5rem .75rem;
}

.pos-approval-hint {
    color: #6b7280;
    font-size: .83rem;
    margin-bottom: 1rem;
}

.pos-approval-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    border-radius: 7px;
    color: #b91c1c;
    font-size: .83rem;
    margin-bottom: .75rem;
    padding: .5rem .75rem;
}

.pos-field { margin-bottom: .85rem; }
.pos-field label {
    color: #374151;
    display: block;
    font-size: .82rem;
    font-weight: 700;
    margin-bottom: .35rem;
}

.pos-input-group {
    align-items: center;
    background: #f9fafb;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    display: flex;
    gap: .5rem;
    padding: .45rem .7rem;
}
.pos-input-group i { color: #9ca3af; font-size: .85rem; }
.pos-input-group input {
    background: transparent;
    border: none;
    color: #111827;
    flex: 1;
    font-size: .88rem;
    outline: none;
}

/* ---- Theme modes ---- */
.pos-shell--light .pos-checkout {
    background: #ffffff;
    border-left: 1px solid #e2e8f0;
    color: #0f172a;
}

.pos-shell--light .pos-co-section {
    border-bottom-color: #e5e7eb;
}

.pos-shell--light .pos-co-label {
    color: #475569;
}

.pos-shell--light .pos-badge,
.pos-shell--light .pos-dtype,
.pos-shell--light .pos-qty,
.pos-shell--light .pos-pay-list {
    background: #f1f5f9;
    border-color: #e2e8f0;
}

.pos-shell--light .pos-co-input,
.pos-shell--light .pos-disc-input,
.pos-shell--light .pos-select,
.pos-shell--light .pos-input-group {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}

.pos-shell--light .pos-co-input::placeholder,
.pos-shell--light .pos-disc-input::placeholder {
    color: #94a3b8;
}

.pos-shell--light .pos-select option {
    background: #ffffff;
    color: #0f172a;
}

.pos-shell--light .pos-patient-drop {
    background: #ffffff;
    border-color: #cbd5e1;
}

.pos-shell--light .pos-mode-switch {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.pos-shell--light .pos-mode-btn { color: #64748b; }
.pos-shell--light .pos-mode-btn:hover { background: #e2e8f0; color: #0f172a; }
.pos-shell--light .pos-mode-btn--active { background: #dbeafe; color: #1d4ed8; }
.pos-shell--light .pos-mode-btn--direct { background: #dcfce7; color: #15803d; }
.pos-shell--light .pos-direct-panel { background: #f0fdf4; border-color: #bbf7d0; }
.pos-shell--light .pos-direct-panel__status { color: #15803d; }

.pos-shell--light .pos-patient-row {
    border-bottom-color: #e5e7eb;
}

.pos-shell--light .pos-patient-row:hover {
    background: #f8fafc;
}

.pos-shell--light .pos-patient-row__name,
.pos-shell--light .pos-patient-selected__name,
.pos-shell--light .pos-cart-item__name,
.pos-shell--light .pos-sum-row--total,
.pos-shell--light .pos-pay-subtotal {
    color: #0f172a;
}

.pos-shell--light .pos-patient-row__meta,
.pos-shell--light .pos-cart-item__price,
.pos-shell--light .pos-sum-row--sub,
.pos-shell--light .pos-pay-hint,
.pos-shell--light .pos-part-note {
    color: #64748b;
}

.pos-shell--light .pos-cart-empty {
    color: #94a3b8;
}

.pos-shell--light .pos-cart-item {
    background: #ffffff;
    border-bottom-color: #e5e7eb;
}

.pos-shell--light .pos-cart-item:hover {
    background: #f8fafc;
}

.pos-shell--light .pos-qty button,
.pos-shell--light .pos-dtype button,
.pos-shell--light .pos-pay-method {
    color: #475569;
}

.pos-shell--light .pos-qty button:hover,
.pos-shell--light .pos-pay-method:hover {
    background: #e2e8f0;
    color: #0f172a;
}

.pos-shell--light .pos-qty input {
    color: #0f172a;
}

.pos-shell--light .pos-sum-row--total {
    border-top-color: #e5e7eb;
}

.pos-shell--light .pos-pay-entry {
    border-bottom-color: #e5e7eb;
}

.pos-shell--light .pos-pay-subtotal {
    background: #f8fafc;
    border-top-color: #e5e7eb;
}

.pos-shell--light .pos-icon-btn--ghost {
    color: #94a3b8;
}

.pos-shell--light .pos-icon-btn--ghost:hover {
    background: #f1f5f9;
    color: #475569;
}

.pos-shell--dark {
    background: #0f172a;
}

.pos-shell--dark .pos-products {
    background: #111827;
    border-right-color: #1f2937;
}

.pos-shell--dark .pos-search-bar,
.pos-shell--dark .pos-product,
.pos-shell--dark .pos-cat {
    background: #1e293b;
    border-color: #334155;
}

.pos-shell--dark .pos-search-input,
.pos-shell--dark .pos-product__name {
    color: #f8fafc;
}

.pos-shell--dark .pos-search-count {
    background: #334155;
    color: #cbd5e1;
}

.pos-shell--dark .pos-cat {
    color: #cbd5e1;
}

.pos-shell--dark .pos-cat:hover {
    background: #334155;
}

.pos-shell--dark .pos-product:hover:not(.pos-product--oos) {
    border-color: #60a5fa;
    box-shadow: 0 4px 18px rgba(59,130,246,.25);
}

.pos-shell--dark .pos-product__icon {
    background: rgba(37,99,235,.18);
    color: #93c5fd;
}

/* ---- Responsive ---- */
@media (max-width: 900px) {
    .pos-body { flex-direction: column; overflow-y: auto; }
    .pos-products { height: 55vh; overflow: hidden; }
    .pos-checkout { height: auto; overflow-y: visible; width: 100%; }
    .pos-cart-section { min-height: 220px; }
    .pos-cart-list { max-height: 360px; }
    .pos-shell { height: auto; overflow: auto; }
}

@media (min-width: 901px) and (max-width: 1199.98px) {
    .pos-checkout { width: 430px; }
    .pos-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
}
</style>

{{-- ===================================================================
     SCRIPTS
     =================================================================== --}}
<script>
window._receiptData = null;

window.addEventListener('receipt-data-ready', function(event) {
    window._receiptData = event.detail;
    var autoPrintData = Object.assign({}, event.detail, { clinic_logo: null });
    window.buildAndPrint(autoPrintData);
});


function updateTime() {
    var el = document.getElementById('currentTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US', {
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
    });
}
updateTime();
setInterval(updateTime, 1000);
</script>
</section>
