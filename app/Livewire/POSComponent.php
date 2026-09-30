<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\Patient;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Setting;
use App\Models\AuditTrail;
use App\Models\DiscountApprovalRequest;
use App\Models\SaleAdjustment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\SmsService;
use App\Models\SmsTemplate;
use Livewire\Attributes\Locked;

class POSComponent extends Component
{
    use WithPagination;

    protected $listeners = [
        'confirmCheckout' => 'checkout',
        'sellWithoutPendingDiscount' => 'sellWithoutPendingDiscount',
    ];

    protected $paginationTheme = 'bootstrap';

    public $cart = [];
    public $totalAmount = 0;

    public $patientId;
    public $patientSearchTerm = '';
    public $searchResults = [];
    public $showPatientDropdown = false;
    public $purchaseMode = 'patient';
    public $directCustomerName = '';
    public $addToOpenVisitBill = true;

    public $productSearchTerm = '';
    public $selectedCategoryId = '';

    // Frame editing on prescription carts
    public $hasPrescriptionCart        = false;
    public $prescriptionConsultationId = null;
    #[Locked]
    public $visitClearanceUuid = null;
    public $frameSearchTerm            = '';
    public $frameSearchResults         = [];

    // Split payment entries: [['method' => 'cash', 'amount' => 200.00], ...]
    public $payments         = [];
    public $newPaymentMethod = 'cash';
    public $newPaymentAmount = '';

    public $amountPaid = 0; // computed: sum of $payments
    public $change     = 0;

    // Discount
    public $discountType   = 'percentage'; // 'percentage' | 'fixed'
    public $discountValue  = 0;
    public $discountAmount = 0;
    public $finalAmount    = 0;

    // Discount approval
    public $discountApproved     = false;
    public $discountApprovedBy   = null;   // approver name (display only)
    public $discountApprovedById = null;   // approver user_id (saved to sale)
    public $showApprovalModal    = false;
    public $approvalEmail        = '';
    public $approvalPassword     = '';
    public $approvalError        = '';
    public $pendingDiscountApprovalId = null;
    public $pendingDiscountApprovalStatus = null;

    public $lastSaleId;

    // Receipt data for inline printing
    public $receiptData = null;
    public $showReceipt = false;

    // Guard flag — prevents double-firing from Livewire listener + Alpine
    public $checkoutProcessing = false;
    public $checkoutIdempotencyKey;

    // Part payment
    public $isPartPayment     = false;
    public $hasFramesOrLenses = false;

    protected $casts = [
        'amountPaid'     => 'float',
        'totalAmount'    => 'float',
        'change'         => 'float',
        'discountValue'  => 'float',
        'discountAmount' => 'float',
        'finalAmount'    => 'float',
    ];

    // Within-request cache — reset on each Livewire hydration cycle (private, not persisted)
    private $cachedCartProducts = null;

    public function mount()
    {
        $this->rotateCheckoutIdempotencyKey();
        $this->calculateTotal();
    }

    /* ===================== PATIENT ===================== */

    public function selectPatientPurchaseMode()
    {
        $this->purchaseMode = 'patient';
        $this->directCustomerName = '';
    }

    public function selectDirectPurchaseMode()
    {
        if ($this->patientId || $this->hasPrescriptionCart) {
            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'Clear the selected patient or Doctor Cart before starting a direct purchase.',
            ]);
            return;
        }

        $this->purchaseMode = 'direct';
        $this->isPartPayment = false;
        $this->patientSearchTerm = '';
        $this->searchResults = [];
        $this->showPatientDropdown = false;
    }

    public function updatedPatientSearchTerm()
    {
        if (strlen($this->patientSearchTerm) >= 2) {
            $this->searchResults = Patient::where('name', 'like', '%' . $this->patientSearchTerm . '%')
                ->orWhere('contact', 'like', '%' . $this->patientSearchTerm . '%')
                ->orWhere('pxnumber', 'like', '%' . $this->patientSearchTerm . '%')
                ->limit(10)
                ->get()
                ->map(fn ($p) => [
                    'id'       => $p->id,
                    'name'     => $p->name,
                    'contact'  => $p->contact  ?? '',
                    'pxnumber' => $p->pxnumber ?? '',
                ])
                ->toArray();
            $this->showPatientDropdown = true;
        } else {
            $this->showPatientDropdown = false;
        }
    }

    public function selectPatient($id)
    {
        $patient = Patient::find($id);
        if (!$patient) return;

        $this->patientId           = $patient->id;
        $this->purchaseMode        = 'patient';
        $this->directCustomerName  = '';
        $this->patientSearchTerm   = $patient->name;
        $this->showPatientDropdown = false;

        // This loads items if a Doctor already prescribed them
        $this->loadPatientCart();

        $this->dispatch('notify', ...[
            'type'    => 'info',
            'message' => 'Patient selected. You can now add items to the cart.'
        ]);
    }

    public function clearPatient()
    {
        $this->patientId                  = null;
        $this->patientSearchTerm          = '';
        $this->cart                       = [];
        $this->payments                   = [];
        $this->newPaymentMethod           = 'cash';
        $this->newPaymentAmount           = '';
        $this->amountPaid                 = 0;
        $this->discountValue              = 0;
        $this->hasPrescriptionCart        = false;
        $this->prescriptionConsultationId = null;
        $this->visitClearanceUuid         = null;
        $this->frameSearchTerm            = '';
        $this->frameSearchResults         = [];
        $this->isPartPayment              = false;
        $this->addToOpenVisitBill         = true;
        $this->resetDiscountApproval();
        $this->calculateTotal();

        $this->dispatch('notify', ...[
            'type'    => 'info',
            'message' => 'Patient cleared. Cart reset.'
        ]);
    }

    /* ===================== PENDING CARTS ===================== */

    public function loadAllPendingCarts()
    {
        try {
            Log::info('=== loadAllPendingCarts START ===');

            // Fetch doctor prescription carts that are waiting for cashier checkout.
            $pendingCarts = Cart::with(['product', 'patient', 'dispensedBy'])
                ->where('purchased', false)
                ->where('status', 'pending')
                ->whereNotNull('consultation_id')
                ->where('consultation_id', '!=', 0)
                ->orderBy('created_at', 'desc')
                ->limit(500)
                ->get();

            if ($pendingCarts->isEmpty()) {
                $this->dispatch('notify', ...[
                    'type'    => 'info',
                    'message' => 'No doctor prescription carts are waiting.'
                ]);
                return;
            }

            // Group the items by patient so one patient shows as one "Order"
            $groupedCarts = $pendingCarts->groupBy('patient_id');
            $cartsData    = [];

            foreach ($groupedCarts as $patientId => $items) {
                $firstItem = $items->first();
                $patient   = $firstItem->patient;
                $dispenser = $firstItem->dispensedBy; // The Doctor/Pharmacist who added items

                $cartsData[] = [
                    'patient_id'       => $patientId,
                    'patient_name'     => $patient ? $patient->name : 'Walk-in Patient',
                    'patient_contact'  => $patient ? $patient->contact : 'N/A',
                    'patient_number'   => $patient ? $patient->pxnumber : 'N/A',
                    // Show the name of the person who prepared the order
                    'cashier_name'     => $dispenser ? $dispenser->name : 'System',
                    'cashier_id'       => $firstItem->dispensed_by,
                    'item_count'       => $items->count(),
                    'total_quantity'   => $items->sum('quantity'),
                    'total_amount'     => (float) $items->sum('total'),
                    'created_at'       => $firstItem->created_at->format('d M Y, h:i A'),
                    'created_at_human' => $firstItem->created_at->diffForHumans(),
                    'consultation_id'   => $firstItem->consultation_id,
                    // 'is_mine' tells the Cashier if THEY were the one who added the items
                    'is_mine'          => $firstItem->dispensed_by == Auth::id(),
                    'items'            => $items->map(function ($item) {
                        return [
                            'product_name' => $item->product ? $item->product->name : 'Unknown Product',
                            'quantity'     => $item->quantity,
                            'frequency'    => $item->frequency,
                            'duration_value' => $item->duration_value,
                            'duration_unit' => $item->duration_unit,
                            'eye'          => $item->eye,
                            'price'        => (float) $item->price,
                            'total'        => (float) $item->total,
                        ];
                    })->toArray()
                ];
            }

            // Sort so that carts created by the current user appear at the top
            usort($cartsData, function ($a, $b) {
                return $b['is_mine'] <=> $a['is_mine'];
            });

            // Send the data to the Alpine.js modal
            $this->dispatch('show-pending-carts', ...[
                'carts'         => $cartsData,
                'totalCarts'    => count($cartsData),
                'currentUserId' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('loadAllPendingCarts ERROR: ' . $e->getMessage());
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Failed to load queue: ' . $e->getMessage()
            ]);
        }
    }

    public function loadApprovedDiscounts()
    {
        $requests = DiscountApprovalRequest::with(['patient', 'cashier', 'approver'])
            ->where('status', DiscountApprovalRequest::STATUS_APPROVED)
            ->where('cashier_id', Auth::id())
            ->latest('approved_at')
            ->latest()
            ->get();

        $approvedDiscounts = $requests
            ->filter(function ($request) {
                if ($this->discountRequestCartIsStillOpen($request)) {
                    return true;
                }

                $request->delete();
                return false;
            })
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'patient_id' => $request->patient_id,
                    'patient_name' => $request->patient->name ?? 'Walk-in Patient',
                    'patient_number' => $request->patient->pxnumber ?? 'N/A',
                    'cashier_name' => $request->cashier->name ?? 'Cashier',
                    'approver_name' => $request->approver->name ?? 'Manager',
                    'discount_type' => $request->discount_type,
                    'discount_value' => (float) $request->discount_value,
                    'discount_amount' => (float) $request->discount_amount,
                    'gross_amount' => (float) $request->gross_amount,
                    'final_amount' => (float) $request->final_amount,
                    'approved_at' => optional($request->approved_at)->format('d M Y, h:i A') ?? $request->updated_at->format('d M Y, h:i A'),
                    'approved_at_human' => optional($request->approved_at ?? $request->updated_at)->diffForHumans(),
                    'items' => collect($request->cart_snapshot ?? [])->map(function ($item) {
                        return [
                            'name' => $item['name'] ?? 'Item',
                            'quantity' => (int) ($item['quantity'] ?? 1),
                            'total' => (float) ($item['total'] ?? $item['subtotal'] ?? 0),
                        ];
                    })->values()->toArray(),
                ];
            })
            ->values()
            ->toArray();

        if (empty($approvedDiscounts)) {
            $this->dispatch('notify', ...[
                'type' => 'info',
                'message' => 'No approved discounts are waiting for you.',
            ]);
        }

        $this->dispatch('show-approved-discounts', ...[
            'discounts' => $approvedDiscounts,
            'totalDiscounts' => count($approvedDiscounts),
        ]);
    }

    public function applyApprovedDiscount($requestId)
    {
        $request = DiscountApprovalRequest::with(['patient', 'approver'])
            ->where('cashier_id', Auth::id())
            ->where('status', DiscountApprovalRequest::STATUS_APPROVED)
            ->find($requestId);

        if (!$request) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Approved discount was not found or has already been used.',
            ]);
            return;
        }

        if (!$this->discountRequestCartIsStillOpen($request)) {
            $request->delete();

            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'This approved discount was removed because the cart was already sold or deleted.',
            ]);
            return;
        }

        if ($request->patient_id) {
            $this->patientId = $request->patient_id;
            $this->purchaseMode = 'patient';
            $this->directCustomerName = '';
            $this->patientSearchTerm = $request->patient->name ?? '';
            $this->showPatientDropdown = false;
            $this->loadPatientCart(null, $this->discountRequestCartIds($request)->toArray());
        }

        if (empty($this->cart)) {
            $this->loadCartFromDiscountSnapshot($request->cart_snapshot ?? []);
        }

        if (!$this->currentCartMatchesDiscountRequest($request)) {
            $this->resetDiscountApproval();

            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'The current cart no longer matches this approved discount.',
            ]);
            return;
        }

        $this->discountType = $request->discount_type;
        $this->discountValue = (float) $request->discount_value;
        $this->calculateTotal();

        $discountEligibleSubtotal = $this->getDiscountEligibleSubtotal();
        if ($discountEligibleSubtotal <= 0) {
            $this->resetDiscountApproval();
            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'Approved discounts can only be applied to Frames and Lenses.',
            ]);
            return;
        }

        $this->discountAmount = min((float) $request->discount_amount, (float) $discountEligibleSubtotal);
        $this->finalAmount = max(0, (float) $this->totalAmount - (float) $this->discountAmount);
        $this->discountApproved = true;
        $this->discountApprovedBy = $request->approver->name ?? 'Manager';
        $this->discountApprovedById = $request->approved_by;
        $this->pendingDiscountApprovalId = $request->id;
        $this->pendingDiscountApprovalStatus = $request->status;
        $this->updateChange();
        $this->newPaymentAmount = $this->finalAmount > 0 ? $this->finalAmount : '';

        $this->dispatch('close-approved-discounts-modal');
        $this->dispatch('pos-cart-loaded');
        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Approved discount and cart loaded. Proceed with payment.',
        ]);
    }

    public function loadCartFromList($patientId, $consultationId = null)
    {
        $patient = Patient::find($patientId);
        if (!$patient) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Patient not found.'
            ]);
            return;
        }

        $this->patientId = $patient->id;
        $this->purchaseMode = 'patient';
        $this->directCustomerName = '';
        $this->patientSearchTerm = $patient->name;
        $this->showPatientDropdown = false;
        $this->loadPatientCart($consultationId);
        $this->newPaymentAmount = $this->finalAmount > 0 ? $this->finalAmount : '';

        $this->dispatch('close-pending-carts-modal');
        $this->dispatch('pos-cart-loaded');
        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => count($this->cart) . ' item(s) loaded for ' . $patient->name . '. Proceed with payment.'
        ]);
    }

    public function deletePendingCart($patientId, $cashierId)
    {
        try {
            if ($cashierId !== Auth::id()) {
                $this->dispatch('notify', ...[
                    'type'    => 'error',
                    'message' => 'You can only delete your own carts.'
                ]);
                return;
            }

            $deleted = Cart::where('patient_id', $patientId)
                ->where('dispensed_by', $cashierId)
                ->where('purchased', false)
                ->where('status', 'pending')
                ->delete();

            if ($deleted > 0) {
                $this->dispatch('notify', ...[
                    'type'    => 'success',
                    'message' => 'Cart deleted successfully.'
                ]);
                $this->loadAllPendingCarts();
            } else {
                $this->dispatch('notify', ...[
                    'type'    => 'warning',
                    'message' => 'No items found to delete.'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Delete pending cart error: ' . $e->getMessage());
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Failed to delete cart.'
            ]);
        }
    }

    /* ===================== CART ===================== */

    public function addToCart($productId)
    {
        $product = Product::with('category')->find($productId);
        if (!$product || !$product->canSupply()) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Product not available or out of stock.'
            ]);
            return;
        }

        $currentQty = isset($this->cart[$productId]) ? $this->cart[$productId]['quantity'] : 0;
        if (!$product->canSupply($currentQty + 1)) {
            $this->dispatch('notify', ...[
                'type'    => 'warning',
                'message' => 'Cannot add more. Maximum stock: ' . $product->quantity
            ]);
            return;
        }

        $this->cart[$productId] = [
            'product_id' => $productId,
            'quantity'  => $currentQty + 1,
            'frequency' => null,
            'duration_value' => null,
            'duration_unit' => null,
            'eye'       => null,
            'cart_id'   => $this->cart[$productId]['cart_id'] ?? null,
        ];

        $this->resetDiscountApprovalForCartChange();
        $this->calculateTotal();
        $this->persistCart($productId);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => $product->name . ' added to cart.'
        ]);
    }

    public function updateQuantity($cartKey, $qty)
    {
        $productId = $this->getCartItemProductId($cartKey);
        $product = Product::find($productId);
        if (!$product) return;

        $qty = max(1, min((int) $qty, $product->maxSupply()));

        if (isset($this->cart[$cartKey])) {
            $this->cart[$cartKey]['quantity'] = $qty;
        } else {
            $this->cart[$cartKey] = [
                'product_id' => $productId,
                'quantity'  => $qty,
                'frequency' => null,
                'duration_value' => null,
                'duration_unit' => null,
                'eye'       => null,
                'cart_id'   => null,
            ];
        }

        $this->resetDiscountApprovalForCartChange();
        $this->calculateTotal();
        $this->persistCart($cartKey);
    }

    public function removeFromCart($cartKey)
    {
        $cartItem = $this->cart[$cartKey] ?? null;

        // Block removal of non-Frame prescription items
        if (
            is_array($cartItem) &&
            ($cartItem['from_prescription'] ?? false) &&
            !($cartItem['is_frame'] ?? false)
        ) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Prescription items can only be removed if they belong to the Frame category.',
            ]);
            return;
        }

        $productId = $this->getCartItemProductId($cartKey, $cartItem);
        unset($this->cart[$cartKey]);
        $this->resetDiscountApprovalForCartChange();
        $this->calculateTotal();

        if ($this->patientId) {
            if (is_array($cartItem) && !empty($cartItem['cart_id'])) {
                Cart::where('id', $cartItem['cart_id'])
                    ->where('patient_id', $this->patientId)
                    ->where('purchased', false)
                    ->delete();
            } else {
                Cart::where('patient_id', $this->patientId)
                    ->where('dispensed_by', Auth::id())
                    ->where('product_id', $productId)
                    ->where('purchased', false)
                    ->delete();
            }
        }

        $this->dispatch('notify', ...[
            'type'    => 'info',
            'message' => 'Item removed from cart.'
        ]);
    }

    public function clearCart()
    {
        if (empty($this->cart)) return;

        if ($this->patientId) {
            Cart::where('patient_id', $this->patientId)
                ->where('purchased', false)
                ->delete();
        }

        $this->cart                = [];
        $this->payments            = [];
        $this->newPaymentMethod    = 'cash';
        $this->newPaymentAmount    = '';
        $this->amountPaid          = 0;
        $this->discountValue       = 0;
        $this->hasPrescriptionCart        = false;
        $this->prescriptionConsultationId = null;
        $this->visitClearanceUuid         = null;
        $this->frameSearchTerm            = '';
        $this->frameSearchResults         = [];
        $this->rotateCheckoutIdempotencyKey();
        $this->resetDiscountApproval();
        $this->calculateTotal();

        $this->dispatch('notify', ...[
            'type'    => 'info',
            'message' => 'Cart cleared successfully.'
        ]);
    }

    /* ===================== FRAME EDITING ===================== */

    private function isFrameProduct(Product $product): bool
    {
        return str_contains(strtolower($product->category->name ?? ''), 'frame');
    }

    public function updatedFrameSearchTerm()
    {
        if (strlen($this->frameSearchTerm) < 2) {
            $this->frameSearchResults = [];
            return;
        }

        $frameCategoryIds = Category::whereRaw('LOWER(name) LIKE ?', ['%frame%'])->pluck('id');

        $this->frameSearchResults = Product::with('category')
            ->whereIn('category_id', $frameCategoryIds)
            ->where(fn ($q) =>
                $q->where('name', 'like', '%' . $this->frameSearchTerm . '%')
                  ->orWhere('batch_number', 'like', '%' . $this->frameSearchTerm . '%')
            )
            ->inStock()
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'batch' => $p->batch_number ?? '',
                'price' => (float) $p->selling_price,
                'stock' => $p->made_to_order ? 'Made to order' : $p->quantity,
            ])
            ->toArray();
    }

    public function addFrameProduct($productId)
    {
        $product = Product::with('category')->find($productId);

        if (!$product) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Product not found.']);
            return;
        }

        if (!$this->isFrameProduct($product)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Only Frame category products can be added here.']);
            return;
        }

        if (!$product->canSupply()) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'This frame is out of stock.']);
            return;
        }

        // Check if already in cart; if so, increment quantity
        foreach ($this->cart as $cartKey => $cartItem) {
            $existingProductId = $this->getCartItemProductId($cartKey, $cartItem);
            if ($existingProductId === (int) $productId) {
                $currentQty = is_array($cartItem) ? $cartItem['quantity'] : $cartItem;
                if (!$product->canSupply($currentQty + 1)) {
                    $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Maximum stock reached for this frame.']);
                    return;
                }
                if (is_array($this->cart[$cartKey])) {
                    $this->cart[$cartKey]['quantity'] = $currentQty + 1;
                } else {
                    $this->cart[$cartKey] = $currentQty + 1;
                }
                $this->resetDiscountApprovalForCartChange();
                $this->calculateTotal();
                $this->persistCart($cartKey);
                $this->frameSearchTerm    = '';
                $this->frameSearchResults = [];
                $this->dispatch('notify', ...['type' => 'success', 'message' => $product->name . ' quantity updated.']);
                return;
            }
        }

        // New item — add as a regular (non-prescription) cart entry
        $this->cart[$productId] = [
            'product_id'       => (int) $productId,
            'quantity'         => 1,
            'frequency'        => null,
            'eye'              => null,
            'cart_id'          => null,
            'from_prescription' => false,
            'is_frame'         => true,
        ];

        $this->resetDiscountApprovalForCartChange();
        $this->calculateTotal();
        $this->persistCart($productId);

        $this->frameSearchTerm    = '';
        $this->frameSearchResults = [];

        $this->dispatch('notify', ...['type' => 'success', 'message' => $product->name . ' added to cart.']);
    }

    /* ===================================================== */

    public function updateCartItemMetadata($cartKey, $frequency, $eye)
    {
        if (!isset($this->cart[$cartKey])) return;

        $productId = $this->getCartItemProductId($cartKey);
        $product = Product::with('category')->find($productId);

        if (!$product) {
            return;
        }

        if (!$product->isDrugCategory()) {
            $frequency = null;
            $eye = null;
        }

        if (is_array($this->cart[$cartKey])) {
            $this->cart[$cartKey]['product_id'] = $productId;
            $this->cart[$cartKey]['frequency'] = $frequency ?: null;
            $this->cart[$cartKey]['eye']       = $eye ?: null;
        } else {
            $qty = $this->cart[$cartKey];
            $this->cart[$cartKey] = [
                'product_id' => $productId,
                'quantity'  => $qty,
                'frequency' => $frequency ?: null,
                'eye'       => $eye ?: null,
                'cart_id'   => null,
            ];
        }

        $this->resetDiscountApprovalForCartChange();
        $this->persistCart($cartKey);

        if ($product->isDrugCategory()) {
            $this->dispatch('notify', ...[
                'type'    => 'success',
                'message' => 'Frequency and eye information updated.'
            ]);
        }
    }

    /* ===================== TOTALS ===================== */

    private function getCartItemProductId($cartKey, $cartItem = null): ?int
    {
        $cartItem = $cartItem ?? ($this->cart[$cartKey] ?? null);

        if (is_array($cartItem) && !empty($cartItem['product_id'])) {
            return (int) $cartItem['product_id'];
        }

        return is_numeric($cartKey) ? (int) $cartKey : null;
    }

    private function getCartProductIds(): array
    {
        return collect($this->cart)
            ->map(fn ($cartItem, $cartKey) => $this->getCartItemProductId($cartKey, $cartItem))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    // Fetches cart products once per request; subsequent calls return the cached collection.
    private function fetchCartProducts(): \Illuminate\Support\Collection
    {
        if ($this->cachedCartProducts === null) {
            $productIds = $this->getCartProductIds();
            $this->cachedCartProducts = $productIds
                ? Product::with('category')->whereIn('id', $productIds)->get()->keyBy('id')
                : collect();
        }
        return $this->cachedCartProducts;
    }

    private function isDiscountEligibleProduct($product): bool
    {
        $categoryName = strtolower($product->category->name ?? '');

        return str_contains($categoryName, 'frame') || str_contains($categoryName, 'lens');
    }

    private function getDiscountEligibleSubtotal(): float
    {
        $products = $this->fetchCartProducts();

        return round(collect($this->cart)->map(function ($cartItem, $cartKey) use ($products) {
            $productId = $this->getCartItemProductId($cartKey, $cartItem);
            $product = $products[$productId] ?? null;

            if (!$product || !$this->isDiscountEligibleProduct($product)) {
                return 0;
            }

            $qty = is_array($cartItem) ? ($cartItem['quantity'] ?? 1) : $cartItem;

            return (float) $qty * (float) $product->selling_price;
        })->sum(), 2);
    }

    private function calculateTotal()
    {
        $this->totalAmount = 0;

        if (empty($this->cart)) {
            $this->hasFramesOrLenses = false;
            $this->isPartPayment     = false;
            $this->discountAmount    = 0;
            $this->finalAmount       = 0;
            $this->updateChange();
            return;
        }

        $this->cachedCartProducts = null; // invalidate so we get fresh data after cart changes
        $products = $this->fetchCartProducts();
        foreach ($this->cart as $cartKey => $cartItem) {
            $productId = $this->getCartItemProductId($cartKey, $cartItem);

            if (isset($products[$productId])) {
                $qty               = is_array($cartItem) ? $cartItem['quantity'] : $cartItem;
                $this->totalAmount += $qty * $products[$productId]->selling_price;
            }
        }

        $this->totalAmount = (float) $this->totalAmount;

        $this->hasFramesOrLenses = $products->contains(fn ($product) => $this->isDiscountEligibleProduct($product));

        // Apply discount only to Frames and Lenses.
        $discountEligibleSubtotal = $this->getDiscountEligibleSubtotal();
        $discountValue = (float) ($this->discountValue ?? 0);
        if ($discountValue > 0 && $discountEligibleSubtotal > 0) {
            if ($this->discountType === 'percentage') {
                $pct = min(100, $discountValue);
                $this->discountAmount = round($discountEligibleSubtotal * ($pct / 100), 2);
            } else {
                $this->discountAmount = round(min($discountEligibleSubtotal, $discountValue), 2);
            }
        } else {
            $this->discountAmount = 0;
        }
        $this->finalAmount = max(0, $this->totalAmount - $this->discountAmount);

        // Auto-disable part payment if cart no longer has frames/lenses
        if (!$this->hasFramesOrLenses) {
            $this->isPartPayment = false;
        }

        $this->amountPaid = $this->getTotalPaid();
        $this->updateChange();
    }

    /* ===================== SPLIT PAYMENT ===================== */

    public function updatedIsPartPayment($enabled)
    {
        if ($enabled && !$this->patientId) {
            $this->isPartPayment = false;
            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'Part payment requires a registered patient.',
            ]);
        }
    }

    public function addPayment()
    {
        $amount = round((float) ($this->newPaymentAmount ?? 0), 2);

        if ($amount <= 0) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Enter a valid payment amount.',
            ]);
            return;
        }

        $this->payments[] = [
            'method' => $this->newPaymentMethod,
            'amount' => $amount,
        ];

        // Pre-fill next entry with remaining balance
        $remaining = max(0, round($this->finalAmount - $this->getTotalPaid(), 2));
        $this->newPaymentAmount = $remaining > 0 ? $remaining : '';

        $this->amountPaid = $this->getTotalPaid();
        $this->updateChange();
    }

    public function removePayment($index)
    {
        array_splice($this->payments, $index, 1);
        $this->amountPaid = $this->getTotalPaid();

        // Pre-fill remaining after removal
        $remaining = max(0, round($this->finalAmount - $this->getTotalPaid(), 2));
        $this->newPaymentAmount = $remaining > 0 ? $remaining : '';

        $this->updateChange();
    }

    public function selectNewPaymentMethod($method)
    {
        $this->newPaymentMethod = $method;
        // Pre-fill with remaining when switching method
        $remaining = max(0, round($this->finalAmount - $this->getTotalPaid(), 2));
        if ($remaining > 0 && (float) ($this->newPaymentAmount ?? 0) == 0) {
            $this->newPaymentAmount = $remaining;
        }
    }

    private function getTotalPaid(): float
    {
        return round(collect($this->payments)->sum('amount'), 2);
    }

    public function updatedDiscountType()
    {
        $this->resetDiscountApproval();
        $this->calculateTotal();
    }

    public function updatedDiscountValue()
    {
        $this->resetDiscountApproval();
        $this->calculateTotal();
    }

    private function resetDiscountApproval()
    {
        $this->discountApproved     = false;
        $this->discountApprovedBy   = null;
        $this->discountApprovedById = null;
        $this->pendingDiscountApprovalId = null;
        $this->pendingDiscountApprovalStatus = null;
    }

    private function resetDiscountApprovalForCartChange(): void
    {
        if ($this->discountApproved || $this->pendingDiscountApprovalId || (float) $this->discountAmount > 0) {
            $this->resetDiscountApproval();
        }
    }

    public function removeDiscount()
    {
        $this->discountValue = 0;
        $this->discountAmount = 0;
        $this->finalAmount = (float) $this->totalAmount;
        $this->resetDiscountApproval();
        $this->newPaymentAmount = max(0, round($this->finalAmount - $this->getTotalPaid(), 2));
        $this->updateChange();

        $this->dispatch('notify', ...[
            'type' => 'info',
            'message' => 'Discount removed. You can sell at full price.',
        ]);
    }

    public function sellWithoutPendingDiscount()
    {
        $this->deletePendingDiscountRequestsForCurrentCart();
        $this->removeDiscount();
        $this->initiateCheckout();
    }

    public function confirmSellWithoutPendingDiscount($request = null)
    {
        if (!$request) {
            $request = $this->findPendingDiscountRequestForCurrentCart();
        }

        $this->dispatch('confirm-sell-without-pending-discount', ...[
            'discountAmount' => number_format((float) ($request->discount_amount ?? $this->discountAmount), 2),
            'fullAmount' => number_format((float) $this->totalAmount, 2),
            'discountedAmount' => number_format((float) ($request->final_amount ?? $this->finalAmount), 2),
        ]);
    }

    /* ===================== DISCOUNT APPROVAL ===================== */

    public function requestDiscountApproval()
    {
        if (!$this->hasFramesOrLenses || $this->getDiscountEligibleSubtotal() <= 0) {
            $this->removeDiscount();
            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'Discounts can only be applied to Frames and Lenses.',
            ]);
            return;
        }

        if ($this->discountAmount <= 0) {
            $this->dispatch('notify', ...[
                'type'    => 'warning',
                'message' => 'Enter a discount amount first.',
            ]);
            return;
        }

        if (Auth::user()?->hasRole(['Manager', 'Super Admin'])) {
            $this->recordApprovedDiscount(Auth::user());

            $this->dispatch('notify', ...[
                'type' => 'success',
                'message' => 'Discount approved.',
            ]);
            return;
        }

        if ($this->pendingDiscountApprovalId) {
            $request = DiscountApprovalRequest::find($this->pendingDiscountApprovalId);
            if ($request && $request->status === DiscountApprovalRequest::STATUS_PENDING) {
                $this->dispatch('notify', ...[
                    'type' => 'info',
                    'message' => 'Discount approval request is already pending.',
                ]);
                return;
            }
        }

        $snapshot = $this->discountApprovalCartSnapshot();
        $duplicateRequest = $this->findActiveDiscountRequestForSnapshot($snapshot);

        if ($duplicateRequest) {
            $this->pendingDiscountApprovalId = $duplicateRequest->id;
            $this->pendingDiscountApprovalStatus = $duplicateRequest->status;

            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => 'A discount request already exists for one or more products in this cart.',
            ]);
            return;
        }

        $request = DiscountApprovalRequest::create([
            'cashier_id' => Auth::id(),
            'patient_id' => $this->patientId,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'discount_amount' => $this->discountAmount,
            'gross_amount' => $this->totalAmount,
            'final_amount' => $this->finalAmount,
            'cart_snapshot' => $snapshot,
            'status' => DiscountApprovalRequest::STATUS_PENDING,
        ]);

        // Notify all Managers and Super Admins that a discount needs approval
        \App\Services\NotificationService::sendToRoles(
            ['Manager', 'Super Admin'],
            'discount_approval_request',
            'Discount Approval Requested',
            Auth::user()->name . ' is requesting a '
                . $this->discountValue
                . ($this->discountType === 'percentage' ? '%' : ' ' . currency())
                . ' discount.',
            'fas fa-percent',
            'text-warning',
            route('admin.discount-approvals')
        );
        app(\App\Services\OwnerAlerts::class)->discountRequested($request);

        $this->pendingDiscountApprovalId = $request->id;
        $this->pendingDiscountApprovalStatus = $request->status;
        $this->showApprovalModal = false;
        $this->approvalEmail = '';
        $this->approvalPassword = '';
        $this->approvalError = '';

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Discount approval request sent to Manager/Super Admin.',
        ]);
        return;
    }

    public function checkDiscountApprovalStatus()
    {
        if (!$this->pendingDiscountApprovalId || $this->discountApproved) {
            return;
        }

        $request = DiscountApprovalRequest::with('approver')->find($this->pendingDiscountApprovalId);

        if (!$request) {
            $this->pendingDiscountApprovalId = null;
            $this->pendingDiscountApprovalStatus = null;
            return;
        }

        $this->pendingDiscountApprovalStatus = $request->status;

        if ($request->status === DiscountApprovalRequest::STATUS_APPROVED) {
            $this->discountApproved = true;
            $this->discountApprovedBy = $request->approver->name ?? 'Manager';
            $this->discountApprovedById = $request->approved_by;

            $this->dispatch('notify', ...[
                'type' => 'success',
                'message' => 'Discount approved by ' . $this->discountApprovedBy . '. You can complete the sale now.',
            ]);
            return;
        }

        if ($request->status === DiscountApprovalRequest::STATUS_REJECTED) {
            $this->pendingDiscountApprovalId = null;
            $this->pendingDiscountApprovalStatus = null;
            $this->discountValue = 0;
            $this->calculateTotal();

            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Discount request was rejected.',
            ]);
        }
    }

    public function approveDiscount()
    {
        $this->approvalError = '';

        $throttleKey = 'discount-approve:' . (request()->ip() ?? auth()->id());

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->approvalError    = "Too many failed attempts. Please wait {$seconds} seconds before trying again.";
            $this->approvalPassword = '';
            return;
        }

        $approver = \App\Models\User::where('email', trim($this->approvalEmail))
            ->where('is_active', true)
            ->first();

        if (!$approver || !\Illuminate\Support\Facades\Hash::check($this->approvalPassword, $approver->password)) {
            RateLimiter::hit($throttleKey, decay: 600); // 10-minute window
            $this->approvalError    = 'Invalid credentials. Please try again.';
            $this->approvalPassword = '';
            return;
        }

        if (!$approver->hasRole(['Manager', 'Super Admin'])) {
            RateLimiter::hit($throttleKey, decay: 600);
            $this->approvalError    = 'This account does not have permission to approve discounts. A Manager or Super Admin is required.';
            $this->approvalPassword = '';
            return;
        }

        if (config('tenancy.enabled')) {
            $context = app(\App\Support\Tenancy\TenantContext::class);
            $authorizedHere = DB::table('branch_user_role as bur')
                ->join('roles as r', 'r.id', '=', 'bur.role_id')
                ->join('branch_user as bu', function ($join) {
                    $join->on('bu.branch_id', '=', 'bur.branch_id')->on('bu.user_id', '=', 'bur.user_id');
                })
                ->join('clinic_user as cu', 'cu.user_id', '=', 'bur.user_id')
                ->where('bur.user_id', $approver->id)
                ->where('bur.branch_id', $context->branchId())
                ->where('cu.clinic_id', $context->clinicId())
                ->where('bu.status', 'active')->where('cu.status', 'active')
                ->whereIn('r.name', ['Manager', 'Super Admin'])->exists();
            if (!$authorizedHere) {
                RateLimiter::hit($throttleKey, decay: 600);
                $this->approvalError = 'The approver is not authorized in this clinic branch.';
                $this->approvalPassword = '';
                return;
            }
        }

        RateLimiter::clear($throttleKey);

        $this->recordApprovedDiscount($approver);
        $this->showApprovalModal    = false;
        $this->approvalEmail        = '';
        $this->approvalPassword     = '';

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Discount of ' . currency() . ' ' . number_format($this->discountAmount, 2) . ' approved by ' . $approver->name . '.',
        ]);
    }

    private function recordApprovedDiscount(\App\Models\User $approver): void
    {
        $snapshot = $this->discountApprovalCartSnapshot();
        $request = $this->pendingDiscountApprovalId
            ? DiscountApprovalRequest::where('cashier_id', Auth::id())
                ->where('status', DiscountApprovalRequest::STATUS_PENDING)
                ->find($this->pendingDiscountApprovalId)
            : null;

        if (!$request || !$this->currentCartMatchesDiscountRequest($request)) {
            $request = DiscountApprovalRequest::create([
                'cashier_id' => Auth::id(), 'patient_id' => $this->patientId,
                'discount_type' => $this->discountType, 'discount_value' => $this->discountValue,
                'discount_amount' => $this->discountAmount, 'gross_amount' => $this->totalAmount,
                'final_amount' => $this->finalAmount, 'cart_snapshot' => $snapshot,
                'status' => DiscountApprovalRequest::STATUS_PENDING,
            ]);
        }

        $request->update([
            'status' => DiscountApprovalRequest::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
        $this->discountApproved = true;
        $this->discountApprovedBy = $approver->name;
        $this->discountApprovedById = $approver->id;
        $this->pendingDiscountApprovalId = $request->id;
        $this->pendingDiscountApprovalStatus = $request->status;
    }

    public function cancelApproval()
    {
        $this->showApprovalModal = false;
        $this->approvalEmail     = '';
        $this->approvalPassword  = '';
        $this->approvalError     = '';
    }

    private function updateChange()
    {
        $totalPaid    = $this->getTotalPaid();
        $finalAmount  = (float) ($this->finalAmount ?? 0);
        $this->change = max(0, $totalPaid - $finalAmount);
    }

    private function discountApprovalCartSnapshot(): array
    {
        $products = $this->fetchCartProducts();

        return collect($this->cart)->map(function ($cartItem, $cartKey) use ($products) {
            $productId = $this->getCartItemProductId($cartKey, $cartItem);
            $product = $products[$productId] ?? null;
            $quantity = is_array($cartItem) ? ($cartItem['quantity'] ?? 1) : $cartItem;

            return [
                'product_id' => $productId,
                'name' => $product->name ?? 'Unknown product',
                'quantity' => (int) $quantity,
                'price' => (float) ($product->selling_price ?? 0),
                'subtotal' => (float) ($quantity * ($product->selling_price ?? 0)),
                'frequency' => is_array($cartItem) ? ($cartItem['frequency'] ?? null) : null,
                'duration_value' => is_array($cartItem) ? ($cartItem['duration_value'] ?? null) : null,
                'duration_unit' => is_array($cartItem) ? ($cartItem['duration_unit'] ?? null) : null,
                'eye' => is_array($cartItem) ? ($cartItem['eye'] ?? null) : null,
                'cart_id' => is_array($cartItem) ? ($cartItem['cart_id'] ?? null) : null,
            ];
        })->values()->toArray();
    }

    private function findActiveDiscountRequestForSnapshot(array $snapshot): ?DiscountApprovalRequest
    {
        $productIds = collect($snapshot)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return null;
        }

        return DiscountApprovalRequest::whereIn('status', [
                DiscountApprovalRequest::STATUS_PENDING,
                DiscountApprovalRequest::STATUS_APPROVED,
            ])
            ->when($this->patientId, fn ($query) => $query->where('patient_id', $this->patientId))
            ->when(!$this->patientId, fn ($query) => $query->where('cashier_id', Auth::id())->whereNull('patient_id'))
            ->latest()
            ->get()
            ->first(function ($request) use ($productIds) {
                if (!$this->discountRequestCartIsStillOpen($request)) {
                    $request->delete();
                    return false;
                }

                $requestProductIds = collect($request->cart_snapshot ?? [])
                    ->pluck('product_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id);

                return $requestProductIds->intersect($productIds)->isNotEmpty();
            });
    }

    private function discountRequestCartIds(DiscountApprovalRequest $request)
    {
        return collect($request->cart_snapshot ?? [])
            ->pluck('cart_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function discountRequestProductIds(DiscountApprovalRequest $request)
    {
        return collect($request->cart_snapshot ?? [])
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function discountRequestCartIsStillOpen(DiscountApprovalRequest $request): bool
    {
        $cartIds = $this->discountRequestCartIds($request);

        if ($cartIds->isNotEmpty()) {
            $query = Cart::whereIn('id', $cartIds)
                ->where('purchased', false)
                ->where('status', 'pending');

            if ($request->patient_id) {
                $query->where('patient_id', $request->patient_id);
            }

            return (int) $query->count() === $cartIds->count();
        }

        $productIds = $this->discountRequestProductIds($request);

        if ($productIds->isEmpty()) {
            return false;
        }

        if (!$request->patient_id) {
            return true;
        }

        $openProductIds = Cart::where('patient_id', $request->patient_id)
            ->where('purchased', false)
            ->where('status', 'pending')
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $productIds->diff($openProductIds)->isEmpty();
    }

    private function snapshotQuantities(array $snapshot): array
    {
        return collect($snapshot)
            ->filter(fn ($item) => !empty($item['product_id']))
            ->groupBy(fn ($item) => (int) $item['product_id'])
            ->map(fn ($items) => (int) $items->sum(fn ($item) => (int) ($item['quantity'] ?? 1)))
            ->sortKeys()
            ->toArray();
    }

    private function currentCartQuantities(): array
    {
        return collect($this->cart)
            ->map(function ($cartItem, $cartKey) {
                return [
                    'product_id' => $this->getCartItemProductId($cartKey, $cartItem),
                    'quantity' => is_array($cartItem) ? (int) ($cartItem['quantity'] ?? 1) : (int) $cartItem,
                ];
            })
            ->filter(fn ($item) => !empty($item['product_id']))
            ->groupBy('product_id')
            ->map(fn ($items) => (int) $items->sum('quantity'))
            ->sortKeys()
            ->toArray();
    }

    private function currentCartMatchesDiscountRequest(DiscountApprovalRequest $request): bool
    {
        $requestCartIds = $this->discountRequestCartIds($request);

        if ($requestCartIds->isNotEmpty()) {
            $currentCartIds = collect($this->cart)
                ->pluck('cart_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values();

            if ($currentCartIds->toArray() !== $requestCartIds->sort()->values()->toArray()) {
                return false;
            }
        }

        return $this->currentCartQuantities() === $this->snapshotQuantities($request->cart_snapshot ?? []);
    }

    private function currentCartIds()
    {
        return collect($this->cart)
            ->pluck('cart_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    private function findPendingDiscountRequestForCurrentCart(): ?DiscountApprovalRequest
    {
        if (empty($this->cart)) {
            return null;
        }

        $currentCartIds = $this->currentCartIds();
        $currentProductIds = collect($this->getCartProductIds())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($currentProductIds->isEmpty()) {
            return null;
        }

        return DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)
            ->when($this->patientId, fn ($query) => $query->where('patient_id', $this->patientId))
            ->when(!$this->patientId, fn ($query) => $query->where('cashier_id', Auth::id())->whereNull('patient_id'))
            ->latest()
            ->get()
            ->first(function ($request) use ($currentCartIds, $currentProductIds) {
                if (!$this->discountRequestCartIsStillOpen($request)) {
                    $request->delete();
                    return false;
                }

                $requestCartIds = $this->discountRequestCartIds($request);

                if ($requestCartIds->isNotEmpty() && $currentCartIds->isNotEmpty()) {
                    return $requestCartIds->intersect($currentCartIds)->isNotEmpty();
                }

                $requestProductIds = $this->discountRequestProductIds($request);

                return $requestProductIds->intersect($currentProductIds)->isNotEmpty()
                    && $this->currentCartQuantities() === $this->snapshotQuantities($request->cart_snapshot ?? []);
            });
    }

    private function deletePendingDiscountRequestsForCurrentCart(): void
    {
        while ($request = $this->findPendingDiscountRequestForCurrentCart()) {
            $request->delete();
        }
    }

    /* ===================== CART PERSISTENCE ===================== */

    private function persistCart($cartKey)
    {
        if (!$this->patientId) return;

        $cartData = $this->cart[$cartKey] ?? null;
        $productId = $this->getCartItemProductId($cartKey, $cartData);
        $product = Product::with('category')->find($productId);
        if (!$product) return;

        $quantity  = is_array($cartData) ? $cartData['quantity'] : $cartData;
        $frequency = is_array($cartData) ? ($cartData['frequency'] ?? null) : null;
        $durationValue = is_array($cartData) ? ($cartData['duration_value'] ?? null) : null;
        $durationUnit = is_array($cartData) ? ($cartData['duration_unit'] ?? null) : null;
        $eye       = is_array($cartData) ? ($cartData['eye'] ?? null) : null;
        $cartId    = is_array($cartData) ? ($cartData['cart_id'] ?? null) : null;

        if (!$product->isDrugCategory()) {
            $frequency = null;
            $durationValue = null;
            $durationUnit = null;
            $eye = null;
        }

        if ($cartId) {
            Cart::where('id', $cartId)
                ->where('patient_id', $this->patientId)
                ->where('purchased', false)
                ->update([
                    'quantity'     => $quantity,
                    'price'        => $product->selling_price,
                    'total'        => $product->selling_price * $quantity,
                    'frequency'    => $frequency,
                    'duration_value' => $durationValue,
                    'duration_unit' => $durationUnit,
                    'eye'          => $eye,
                    'status'       => 'pending',
                    'is_dispensed' => false,
                ]);

            return;
        }

        Cart::updateOrCreate(
            [
                'patient_id'   => $this->patientId,
                'dispensed_by' => Auth::id(),
                'product_id'   => $productId,
                'purchased'    => false,
            ],
            [
                'consultation_id' => $this->prescriptionConsultationId,
                'quantity'        => $quantity,
                'price'           => $product->selling_price,
                'total'           => $product->selling_price * $quantity,
                'frequency'       => $frequency,
                'duration_value'  => $durationValue,
                'duration_unit'   => $durationUnit,
                'eye'             => $eye,
                'status'          => 'pending',
                'is_dispensed'    => false,
            ]
        );
    }

    private function loadPatientCart($consultationId = null, array $cartIds = [])
    {
        if (!$this->patientId) return;

        $this->payments         = [];
        $this->newPaymentMethod = 'cash';
        $this->newPaymentAmount = '';
        $this->discountValue    = 0;
        $this->resetDiscountApproval();

        // Fetch ANY pending items for this patient, regardless of who added them
        $items = Cart::with('product.category')
            ->where('patient_id', $this->patientId)
            ->where('purchased', false)
            ->when(!empty($cartIds), fn ($query) => $query->whereIn('id', $cartIds))
            ->when($consultationId, fn ($query) => $query->where('consultation_id', $consultationId))
            ->get();

        $this->cart = [];
        if ($items->isNotEmpty()) {
            foreach ($items as $item) {
                if ($item->product && $item->product->canSupply((int) $item->quantity)) {
                    $fromPrescription = !empty($item->consultation_id) && $item->consultation_id != 0;
                    $categoryName     = strtolower($item->product->category->name ?? '');
                    $this->cart['cart_' . $item->id] = [
                        'product_id'       => $item->product_id,
                        'quantity'         => $item->quantity,
                        'frequency'        => $item->product->isDrugCategory() ? $item->frequency : null,
                        'duration_value'   => $item->product->isDrugCategory() ? $item->duration_value : null,
                        'duration_unit'    => $item->product->isDrugCategory() ? $item->duration_unit : null,
                        'eye'              => $item->product->isDrugCategory() ? $this->normalizeEye($item->eye) : null,
                        'cart_id'          => $item->id,
                        'from_prescription' => $fromPrescription,
                        'is_frame'         => str_contains($categoryName, 'frame'),
                    ];
                }
            }
            $this->dispatch('notify', ...[
                'type'    => 'success',
                'message' => count($this->cart) . ' prescribed items loaded.'
            ]);
        }

        $this->hasPrescriptionCart = collect($this->cart)
            ->contains(fn ($item) => is_array($item) && ($item['from_prescription'] ?? false));

        // Store the consultation_id from any prescription item so cashier-added frames use it
        $prescriptionItem = $items->first(fn ($i) => !empty($i->consultation_id) && $i->consultation_id != 0);
        $this->prescriptionConsultationId = $prescriptionItem ? $prescriptionItem->consultation_id : null;
        $this->visitClearanceUuid = $this->prescriptionConsultationId
            ? \App\Models\CashierPatientClearance::whereHas('consultation', fn ($query) => $query->whereKey($this->prescriptionConsultationId))->value('uuid')
            : null;

        $this->calculateTotal();
    }

    private function normalizeEye(?string $eye): ?string
    {
        if (!$eye) return null;
        return match(strtolower(trim($eye))) {
            'both eyes', 'ou', 'both'       => 'OU',
            'od (right eye)', 'od', 'right'  => 'OD',
            'os (left eye)',  'os', 'left'   => 'OS',
            default                          => null,
        };
    }

    private function loadCartFromDiscountSnapshot(array $snapshot): void
    {
        $this->cart = [];

        foreach ($snapshot as $item) {
            $productId = $item['product_id'] ?? null;

            if (!$productId) {
                continue;
            }

            $product = Product::with('category')->find($productId);

            if (!$product || !$product->canSupply()) {
                continue;
            }

            $quantity = max(1, min((int) ($item['quantity'] ?? 1), $product->maxSupply()));

            $this->cart[$productId] = [
                'product_id' => (int) $productId,
                'quantity' => $quantity,
                'frequency' => $product->isDrugCategory() ? ($item['frequency'] ?? null) : null,
                'duration_value' => $product->isDrugCategory() ? ($item['duration_value'] ?? null) : null,
                'duration_unit' => $product->isDrugCategory() ? ($item['duration_unit'] ?? null) : null,
                'eye' => $product->isDrugCategory() ? ($item['eye'] ?? null) : null,
                'cart_id' => null,
            ];

            $this->persistCart($productId);
        }

        $this->calculateTotal();
    }

    /* ===================== CHECKOUT ===================== */

    public function initiateCheckout()
    {
        if (empty($this->cart)) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Please add items to cart before checkout.',
            ]);
            return;
        }

        if ($this->purchaseMode === 'direct') {
            $this->validate([
                'directCustomerName' => 'nullable|string|max:150',
            ]);
            $this->directCustomerName = trim($this->directCustomerName);
        }

        if ($this->isPartPayment && !$this->patientId) {
            $this->isPartPayment = false;
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Part payment is not available for Walk-ins or Direct Purchases. Select a registered patient first.',
            ]);
            return;
        }

        if ($this->discountAmount > 0 && !$this->discountApproved) {
            $this->confirmSellWithoutPendingDiscount();
            return;
        }

        if (!$this->discountApproved && $pendingRequest = $this->findPendingDiscountRequestForCurrentCart()) {
            $this->confirmSellWithoutPendingDiscount($pendingRequest);
            return;
        }

        $amountPaid  = $this->getTotalPaid();
        $finalAmount = (float) ($this->finalAmount ?? 0);

        if ($amountPaid <= 0 && $finalAmount > 0) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Please add at least one payment before checkout.',
            ]);
            return;
        }

        if (!$this->isPartPayment && $amountPaid < $finalAmount) {
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Total paid (' . currency() . ' ' . number_format($amountPaid, 2) . ') is less than total due (' . currency() . ' ' . number_format($finalAmount, 2) . ').',
            ]);
            return;
        }

        if ($this->isPartPayment && $amountPaid >= $finalAmount) {
            // Payments cover full amount — treat as full payment
            $this->isPartPayment = false;
        }

        $balance = max(0, $finalAmount - $amountPaid);

        $this->dispatch('show-checkout-confirmation', ...[
            'totalAmount'    => number_format($finalAmount, 2),
            'amountPaid'     => number_format($amountPaid, 2),
            'change'         => number_format($this->change, 2),
            'balance'        => number_format($balance, 2),
            'isPartPayment'  => $this->isPartPayment,
            'itemCount'      => count($this->cart),
            'discountAmount' => $this->discountAmount > 0 ? number_format($this->discountAmount, 2) : null,
        ]);
    }

    public function dismissReceipt()
    {
        $this->receiptData = null;
        $this->showReceipt = false;
    }

    public function checkout()
    {
        // Guard: prevent double-firing from Livewire listener + Alpine
        if ($this->checkoutProcessing) {
            Log::info('=== CHECKOUT already processing — duplicate call ignored ===');
            return;
        }
        $this->checkoutProcessing = true;
        $this->ensureCheckoutIdempotencyKey();

        Log::info('=== CHECKOUT METHOD CALLED ===');

        // Treat Livewire properties as untrusted input on every checkout.
        $this->calculateTotal();
        if (!$this->checkoutReferencesAreValid()) {
            $this->checkoutProcessing = false;
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'The selected patient or prescription cart is no longer valid. Reload the cart.',
            ]);
            return;
        }

        if (empty($this->cart)) {
            $this->checkoutProcessing = false;
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Cart is empty']);
            return;
        }

        if ($this->isPartPayment && !$this->patientId) {
            $this->checkoutProcessing = false;
            $this->isPartPayment = false;
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Part payment requires a registered patient.',
            ]);
            return;
        }

        if ($this->discountAmount > 0 && !$this->discountApproved) {
            $this->checkoutProcessing = false;
            $this->confirmSellWithoutPendingDiscount();
            return;
        }

        if (!$this->discountApproved && $pendingRequest = $this->findPendingDiscountRequestForCurrentCart()) {
            $this->checkoutProcessing = false;
            $this->confirmSellWithoutPendingDiscount($pendingRequest);
            return;
        }

        if ($this->discountAmount > 0 && $this->discountApproved) {
            $approvedRequest = DiscountApprovalRequest::where('id', $this->pendingDiscountApprovalId)
                ->where('cashier_id', Auth::id())
                ->where('status', DiscountApprovalRequest::STATUS_APPROVED)
                ->first();

            if (
                !$approvedRequest ||
                (int) $approvedRequest->approved_by !== (int) $this->discountApprovedById ||
                $approvedRequest->discount_type !== $this->discountType ||
                abs((float) $approvedRequest->discount_value - (float) $this->discountValue) > 0.009 ||
                abs((float) $approvedRequest->discount_amount - (float) $this->discountAmount) > 0.009 ||
                !$this->discountRequestCartIsStillOpen($approvedRequest) ||
                !$this->currentCartMatchesDiscountRequest($approvedRequest)
            ) {
                $this->checkoutProcessing = false;
                $this->resetDiscountApproval();

                $this->dispatch('notify', ...[
                    'type' => 'error',
                    'message' => 'Discount approval is no longer valid for this cart. Request approval again or remove the discount.',
                ]);
                return;
            }
        }

        $amountPaid    = (float) ($this->amountPaid ?? 0);
        $isPartPayment = $this->isPartPayment;
        $finalAmount   = (float) ($this->finalAmount ?? 0);

        if (!$isPartPayment && $amountPaid < $finalAmount) {
            $this->checkoutProcessing = false;
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Insufficient payment']);
            return;
        }

        DB::beginTransaction();

        try {
            // Lock stock rows for the lifetime of this transaction. Concurrent
            // checkouts containing the same products must wait until this sale
            // commits or rolls back, then validate against the latest quantity.
            $products = Product::with('category')
                ->whereIn('id', $this->getCartProductIds())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Identical retries wait on the same stock rows. After the original
            // transaction commits, return its receipt instead of creating
            // another sale or decrementing inventory again.
            $existingSale = $this->findExistingIdempotentSale();

            if ($existingSale) {
                DB::commit();
                $this->checkoutProcessing = false;
                $this->rotateCheckoutIdempotencyKey();

                return redirect()->route('cashier.receipt.show', $existingSale->id);
            }

            $requiredQuantities = [];

            foreach ($this->cart as $cartKey => $cartItem) {
                $productId = $this->getCartItemProductId($cartKey, $cartItem);
                $qty = is_array($cartItem) ? $cartItem['quantity'] : $cartItem;

                if (!$productId) {
                    throw new \Exception('Invalid cart item detected');
                }

                $requiredQuantities[$productId] = ($requiredQuantities[$productId] ?? 0) + $qty;
            }

            // Stock validation
            foreach ($requiredQuantities as $productId => $requiredQuantity) {
                if (!isset($products[$productId]) || !$products[$productId]->canSupply((int) $requiredQuantity)) {
                    $productName = isset($products[$productId]) ? $products[$productId]->name : 'Unknown';
                    throw new \Exception('Insufficient stock for ' . $productName);
                }
            }

            $availableOpenVisitSale = $this->findOpenVisitSale(true);
            $openVisitSale = $this->addToOpenVisitBill ? $availableOpenVisitSale : null;

            if ($availableOpenVisitSale && !$this->addToOpenVisitBill) {
                $oldBill = $availableOpenVisitSale->only(['bill_status', 'bill_version', 'finalized_at']);
                $availableOpenVisitSale->update([
                    'bill_status'  => 'finalized',
                    'finalized_at' => now(),
                    'finalized_by' => Auth::id(),
                    'bill_version' => $availableOpenVisitSale->bill_version + 1,
                ]);
                AuditTrail::record('visit_bill.finalized_separately', "Visit bill {$availableOpenVisitSale->transaction_id} finalized before a separate sale", $availableOpenVisitSale, $oldBill, $availableOpenVisitSale->only(['bill_status', 'bill_version', 'finalized_at', 'finalized_by']), $this->patientId, true);
            }

            // Generate transaction ID using UUID for guaranteed uniqueness
            $transactionId = $openVisitSale?->transaction_id
                ?? now()->format('dmY') . '-' . strtoupper(Str::random(8));

            // Calculate profit
            $total_profit = 0;
            foreach ($this->cart as $cartKey => $cartItem) {
                $productId = $this->getCartItemProductId($cartKey, $cartItem);

                if (isset($products[$productId])) {
                    $qty           = is_array($cartItem) ? $cartItem['quantity'] : $cartItem;
                    $total_profit += ($products[$productId]->selling_price - $products[$productId]->cost_price) * $qty;
                }
            }

            // For full payments cap amount_paid at the total (excess is change, not revenue)
            $recordedAmountPaid = $isPartPayment ? $amountPaid : min($amountPaid, $finalAmount);

            if ($openVisitSale) {
                $oldBill = $openVisitSale->only(['total_amount', 'amount_paid', 'payment_status', 'bill_status', 'bill_version', 'discount_amount', 'profit']);
                $combinedTotal = (float) $openVisitSale->total_amount + $finalAmount;
                $combinedPaid = (float) $openVisitSale->amount_paid + $recordedAmountPaid;
                $paymentStatus = $combinedPaid >= $combinedTotal
                    ? 'paid'
                    : ($combinedPaid > 0 ? 'partial' : 'unpaid');
                $combinedDiscount = (float) $openVisitSale->discount_amount + $this->discountAmount;

                $openVisitSale->update([
                    'consultation_id'      => $openVisitSale->consultation_id ?: $this->prescriptionConsultationId,
                    'total_amount'         => $combinedTotal,
                    'amount_paid'          => $combinedPaid,
                    'payment_status'       => $paymentStatus,
                    'bill_status'          => $isPartPayment ? 'open' : 'finalized',
                    'finalized_at'         => $isPartPayment ? null : now(),
                    'finalized_by'         => $isPartPayment ? null : Auth::id(),
                    'bill_version'         => $openVisitSale->bill_version + 1,
                    'profit'               => (float) $openVisitSale->profit + ($isPartPayment ? 0 : max(0, $total_profit - $this->discountAmount)),
                    'idempotency_key'      => $this->checkoutIdempotencyKey,
                    'discount_type'        => $combinedDiscount > 0 ? 'fixed' : null,
                    'discount_value'       => $combinedDiscount > 0 ? $combinedDiscount : null,
                    'discount_amount'      => $combinedDiscount,
                    'discount_approved_by'=> $this->discountAmount > 0 ? $this->discountApprovedById : $openVisitSale->discount_approved_by,
                ]);
                $sale = $openVisitSale->fresh();
            } else {
                $paymentStatus = $isPartPayment ? 'partial' : 'paid';

                $sale = Sales::create([
                'business_line'  => 'clinic',
                'user_id'         => Auth::id(),
                'patient_id'      => $this->patientId,
                'customer_name'   => $this->purchaseMode === 'direct' && !$this->patientId
                    ? ($this->directCustomerName !== '' ? $this->directCustomerName : null)
                    : null,
                'consultation_id' => $this->prescriptionConsultationId,
                'total_amount'    => $finalAmount,
                'amount_paid'     => $recordedAmountPaid,
                'payment_status'  => $paymentStatus,
                'bill_status'     => $isPartPayment ? 'open' : 'finalized',
                'finalized_at'    => $isPartPayment ? null : now(),
                'finalized_by'    => $isPartPayment ? null : Auth::id(),
                'expires_at'      => $isPartPayment ? now()->addDays(30) : null,
                'profit'          => $isPartPayment ? 0 : max(0, $total_profit - $this->discountAmount),
                'transaction_id'  => $transactionId,
                'idempotency_key' => $this->checkoutIdempotencyKey,
                'discount_type'        => $this->discountAmount > 0 ? $this->discountType      : null,
                'discount_value'       => $this->discountAmount > 0 ? $this->discountValue     : null,
                'discount_amount'      => $this->discountAmount,
                'discount_approved_by' => $this->discountAmount > 0 ? $this->discountApprovedById : null,
                ]);
            }

            if ($this->discountAmount > 0) {
                SaleAdjustment::create([
                    'sale_id' => $sale->id,
                    'type' => 'discount',
                    'amount' => $this->discountAmount,
                    'method' => $this->discountType,
                    'input_value' => $this->discountValue,
                    'approved_by' => $this->discountApprovedById,
                    'created_by' => Auth::id(),
                    'reason' => $openVisitSale ? 'Discount applied while appending to visit bill' : 'Checkout discount',
                ]);
            }

            // Log one PaymentTransaction per split-payment entry.
            // Cap each amount at the remaining balance so the total never exceeds
            // the sale amount — the excess the cashier entered is change returned
            // to the customer and must not be recorded as collected revenue.
            $remaining = $finalAmount;
            foreach ($this->payments as $payment) {
                if ($remaining <= 0) break;
                $recordAmount = $isPartPayment
                    ? (float) $payment['amount']
                    : min((float) $payment['amount'], $remaining);
                \App\Models\PaymentTransaction::create([
                    'sale_id'        => $sale->id,
                    'amount'         => $recordAmount,
                    'payment_method' => $payment['method'],
                    'notes'          => $isPartPayment ? 'Initial deposit' : 'Full payment',
                    'collected_by'   => Auth::id(),
                ]);
                $remaining -= $recordAmount;
            }

            // Create sale items & decrement stock (stock reserved even for partial)
            foreach ($this->cart as $cartKey => $cartItem) {
                $productId = $this->getCartItemProductId($cartKey, $cartItem);
                $product   = $products[$productId];
                $qty       = is_array($cartItem) ? $cartItem['quantity'] : $cartItem;
                $frequency = is_array($cartItem) ? ($cartItem['frequency'] ?? null) : null;
                $durationValue = is_array($cartItem) ? ($cartItem['duration_value'] ?? null) : null;
                $durationUnit = is_array($cartItem) ? ($cartItem['duration_unit'] ?? null) : null;
                $eye       = is_array($cartItem) ? ($cartItem['eye'] ?? null)       : null;
                if (!$product->isDrugCategory()) {
                    $frequency = null;
                    $durationValue = null;
                    $durationUnit = null;
                    $eye = null;
                }
                $cartId    = is_array($cartItem) ? ($cartItem['cart_id'] ?? null)  : null;

                SaleItem::create([
                    'sale_id'             => $sale->id,
                    'cart_id'             => $cartId,
                    'product_id'          => $productId,
                    'prescribed_quantity' => $isPartPayment ? $qty : 0,
                    'dispensed_quantity'  => $isPartPayment ? 0    : $qty,
                    'selling_price'       => $product->selling_price,
                    'subtotal'            => $qty * $product->selling_price,
                    'frequency'           => $frequency,
                    'duration_value'      => $durationValue,
                    'duration_unit'       => $durationUnit,
                    'eye'                 => $eye,
                    'notes'               => $isPartPayment ? 'On Hold - Part Payment' : ($frequency ? 'Prescription Sale' : 'Direct POS Sale'),
                ]);

                // Keep a database-level guard in addition to the row lock so an
                // inventory write can never reduce quantity below zero.
                app(\App\Services\Inventory\BranchInventoryService::class)
                    ->decrease($product, (int) $qty);
            }

            // Mark the exact cart rows included in this checkout.
            // Prescription cart rows are created by doctors, so filtering by cashier user id
            // can leave them pending and allow stock to be deducted again on a later sale.
            if ($this->patientId) {
                $cartIds = collect($this->cart)
                    ->filter(fn ($cartItem) => is_array($cartItem) && !empty($cartItem['cart_id']))
                    ->pluck('cart_id')
                    ->unique()
                    ->values();

                if ($cartIds->isNotEmpty()) {
                    Cart::whereIn('id', $cartIds)
                        ->where('patient_id', $this->patientId)
                        ->where('purchased', false)
                        ->update([
                            'purchased'    => true,
                            'status'       => 'completed',
                            'is_dispensed' => !$isPartPayment,
                            'dispensed_at' => !$isPartPayment ? now() : null,
                            'dispensed_by' => Auth::id(),
                        ]);
                } else {
                    Cart::where('patient_id', $this->patientId)
                        ->where('dispensed_by', Auth::id())
                        ->where('purchased', false)
                        ->update([
                            'purchased'    => true,
                            'status'       => 'completed',
                            'is_dispensed' => !$isPartPayment,
                            'dispensed_at' => !$isPartPayment ? now() : null,
                            'dispensed_by' => Auth::id(),
                        ]);
                }
            }

            if ($this->discountAmount > 0 && $this->discountApprovedById) {
                if ($this->pendingDiscountApprovalId) {
                    DiscountApprovalRequest::where('id', $this->pendingDiscountApprovalId)
                        ->where('status', DiscountApprovalRequest::STATUS_APPROVED)
                        ->update(['status' => DiscountApprovalRequest::STATUS_USED]);
                }

                AuditTrail::record(
                    'discount.approved',
                    'Discount of ' . currency() . ' ' . number_format($this->discountAmount, 2) . ' approved by ' . $this->discountApprovedBy . ' for sale ' . $sale->transaction_id,
                    $sale,
                    [],
                    [
                        'discount_type'   => $this->discountType,
                        'discount_value'  => $this->discountValue,
                        'discount_amount' => $this->discountAmount,
                        'approved_by_id'  => $this->discountApprovedById,
                        'approved_by'     => $this->discountApprovedBy,
                    ],
                    $this->patientId
                );
            }

            AuditTrail::record(
                'payment.received',
                'Recorded ' . ($isPartPayment ? 'part payment' : 'full payment') . ' for sale ' . $sale->transaction_id,
                $sale,
                [],
                [
                    'total_amount' => $sale->total_amount,
                    'amount_paid' => $amountPaid,
                    'payment_status' => $paymentStatus,
                    'items' => count($this->cart),
                ],
                $this->patientId
            );

            if ($openVisitSale) {
                AuditTrail::record(
                    'visit_bill.items_appended',
                    'Items and payments appended to visit bill ' . $sale->transaction_id,
                    $sale,
                    $oldBill,
                    array_merge($sale->only(['total_amount', 'amount_paid', 'payment_status', 'bill_status', 'bill_version', 'discount_amount', 'profit', 'finalized_by']), [
                        'appended_quantities' => $requiredQuantities,
                        'payment_count' => count($this->payments),
                    ]),
                    $this->patientId,
                    true
                );
            }

            if ($this->purchaseMode === 'direct' && !$this->patientId) {
                $directCustomer = $sale->customer_display_name;
                $cashierName = Auth::user()->name ?? 'Staff';

                AuditTrail::record(
                    'direct_purchase.completed',
                    'Direct purchase completed for ' . $directCustomer . ' by ' . $cashierName,
                    $sale,
                    [],
                    [
                        'purchase_type' => 'direct',
                        'customer_name' => $directCustomer,
                        'transaction_id' => $sale->transaction_id,
                        'total_amount' => $sale->total_amount,
                    ],
                    null,
                    true
                );
            }

            DB::commit();

            // External delivery must happen only after the sale and stock changes
            // are durable. Delivery failures are logged and never reverse or
            // misreport an already committed checkout.
            $this->sendCommittedSaleReceipt($sale);

            // Low-stock alerts — fire after commit so stock values are final in DB
            $lowStockThreshold = 5;
            foreach (array_keys($requiredQuantities) as $productId) {
                if (!isset($products[$productId]) || $products[$productId]->made_to_order) continue;
                $remaining = (int) Product::whereKey($productId)->value('quantity');
                if ($remaining >= 0 && $remaining <= $lowStockThreshold) {
                    $stockLabel = $remaining === 0 ? 'OUT OF STOCK' : $remaining . ' unit' . ($remaining === 1 ? '' : 's') . ' left';
                    \App\Services\NotificationService::sendToRoles(
                        ['Super Admin', 'Manager'],
                        'low_stock',
                        'Low Stock: ' . $products[$productId]->name,
                        $stockLabel . ' — restock soon.',
                        'fas fa-exclamation-triangle',
                        $remaining === 0 ? 'text-danger' : 'text-warning',
                        route('admin.inventory-alerts')
                    );
                }
            }

            Log::info('=== Sale completed. Sale ID: ' . $sale->id . ' ===');

            $saleData     = Sales::with(['items.product', 'patient', 'user', 'paymentTransactions'])->find($sale->id);
            $changeAmount = $this->change;
            $cumulativePaid = (float) $saleData->paymentTransactions->sum('amount');
            $receiptPayments = $saleData->paymentTransactions->map(fn ($payment) => [
                'method' => $payment->payment_method,
                'amount' => (float) $payment->amount,
            ])->values()->toArray();

            // Fresh settings from DB
            $settings = Setting::getSettings();

            // Build receipt data array
            $this->lastSaleId = $saleData->id;
            $this->receiptData = [
                'sale_id'         => $saleData->id,
                'transaction_id'  => $saleData->transaction_id,
                'created_at'      => $saleData->created_at->format('M d, Y h:i A'),
                'gross_amount'    => (float) $saleData->items->sum('subtotal'),
                'discount_type'   => $saleData->discount_type,
                'discount_value'  => $saleData->discount_value,
                'discount_amount' => $saleData->discount_amount,
                'total_amount'    => $saleData->total_amount,
                'amount_paid'     => $cumulativePaid,
                'balance'         => max(0, (float) $saleData->total_amount - $cumulativePaid),
                'payments'        => $receiptPayments,
                'payment_status'  => $saleData->payment_status,
                'change'         => $isPartPayment ? 0 : $changeAmount,
                'served_by'      => $saleData->user->name ?? 'Staff',
                'clinic_name'    => ($settings && $settings->clinic_name)    ? $settings->clinic_name    : 'PHARMACY POS',
                'clinic_address' => ($settings && $settings->clinic_address) ? $settings->clinic_address : 'Medical Dispensary',
                'clinic_contact' => ($settings && $settings->clinic_contact) ? $settings->clinic_contact : '',
                'clinic_email'   => ($settings && $settings->clinic_email)   ? $settings->clinic_email   : '',
                'clinic_logo'    => $settings ? $settings->logoDataUri() : null,
                'patient'        => $saleData->patient ? [
                    'name'     => $saleData->patient->name,
                    'contact'  => $saleData->patient->contact  ?? 'N/A',
                    'pxnumber' => $saleData->patient->pxnumber ?? 'N/A',
                ] : null,
                'customer_name'  => $saleData->customer_display_name,
                'items' => $saleData->items->map(function ($item) {
                    return [
                        'name'          => $item->product->name ?? 'N/A',
                        'quantity'      => $item->dispensed_quantity,
                        'selling_price' => $item->selling_price,
                        'subtotal'      => $item->subtotal,
                    ];
                })->toArray(),
            ];

            $this->dispatch('receipt-data-ready', ...array_merge(
                $this->receiptData,
                ['printed_at' => now()->format('M d, Y h:i A')]
            ));

            $this->showReceipt = true;

            $this->dispatch('close-processing-modal');

            $this->dispatch('notify', ...[
                'type'    => 'success',
                'message' => $openVisitSale
                    ? 'Items added to the visit bill successfully!'
                    : 'Transaction completed successfully!',
            ]);

            $this->cart             = [];
            $this->payments         = [];
            $this->newPaymentMethod = 'cash';
            $this->newPaymentAmount = '';
            $this->amountPaid       = 0;
            $this->change           = 0;
            $this->totalAmount      = 0;
            $this->discountValue    = 0;
            $this->discountAmount   = 0;
            $this->finalAmount      = 0;
            $this->discountApproved     = false;
            $this->discountApprovedBy   = null;
            $this->discountApprovedById = null;
            $this->pendingDiscountApprovalId = null;
            $this->pendingDiscountApprovalStatus = null;
            $this->isPartPayment        = false;
            $this->hasFramesOrLenses    = false;
            $this->purchaseMode          = 'patient';
            $this->addToOpenVisitBill    = true;
            $this->directCustomerName    = '';
            $this->checkoutProcessing   = false;
            $this->rotateCheckoutIdempotencyKey();

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // The unique index is the final race-condition guard. If another
            // request committed this key first, return that sale rather than
            // reporting the duplicate insert as a failed checkout.
            try {
                if ($existingSale = $this->findExistingIdempotentSale()) {
                    $this->checkoutProcessing = false;
                    $this->rotateCheckoutIdempotencyKey();

                    return redirect()->route('cashier.receipt.show', $existingSale->id);
                }
            } catch (\Throwable $lookupError) {
                Log::warning('Unable to resolve checkout idempotency key after failure.', [
                    'error' => $lookupError->getMessage(),
                ]);
            }

            Log::error('Checkout failed: ' . $e->getMessage());

            $this->checkoutProcessing = false;

            $this->dispatch('close-processing-modal');
            $this->dispatch('notify', ...[
                'type'    => 'error',
                'message' => 'Transaction failed: ' . $e->getMessage(),
            ]);
        }
    }

    private function checkoutReferencesAreValid(): bool
    {
        if ($this->patientId && !Patient::whereKey($this->patientId)->exists()) {
            return false;
        }
        if ($this->prescriptionConsultationId) {
            if (!$this->patientId || !\App\Models\Consultations::whereKey($this->prescriptionConsultationId)
                ->where('patient_id', $this->patientId)->exists()) {
                return false;
            }
        }

        $persisted = collect($this->cart)->filter(fn ($item) => is_array($item) && !empty($item['cart_id']));
        if ($persisted->isEmpty()) {
            return true;
        }
        $rows = Cart::whereIn('id', $persisted->pluck('cart_id')->map(fn ($id) => (int) $id))
            ->where('purchased', false)->where('status', 'pending')
            ->when($this->patientId, fn ($q) => $q->where('patient_id', $this->patientId))
            ->when(!$this->patientId, fn ($q) => $q->whereNull('patient_id')->where('dispensed_by', Auth::id()))
            ->get()->keyBy('id');

        if ($rows->count() !== $persisted->count()) {
            return false;
        }
        return $persisted->every(function ($item) use ($rows) {
            $row = $rows->get((int) $item['cart_id']);
            return $row
                && (int) $row->product_id === (int) ($item['product_id'] ?? 0)
                && (!$this->prescriptionConsultationId || (int) $row->consultation_id === (int) $this->prescriptionConsultationId);
        });
    }

    private function ensureCheckoutIdempotencyKey(): void
    {
        if (
            !is_string($this->checkoutIdempotencyKey) ||
            !preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
                $this->checkoutIdempotencyKey
            )
        ) {
            $this->rotateCheckoutIdempotencyKey();
        }
    }

    private function rotateCheckoutIdempotencyKey(): void
    {
        $this->checkoutIdempotencyKey = (string) Str::uuid();
    }

    private function findExistingIdempotentSale(): ?Sales
    {
        return Sales::where('idempotency_key', $this->checkoutIdempotencyKey)
            ->where('user_id', Auth::id())
            ->first();
    }

    private function sendCommittedSaleReceipt(Sales $sale): void
    {
        if ($sale->payment_status !== 'paid' || !$sale->patient_id) {
            return;
        }

        try {
            $patient = Patient::find($sale->patient_id);

            if (!$patient) {
                Log::warning('Payment receipt delivery skipped: patient not found.', [
                    'sale_id' => $sale->id,
                    'patient_id' => $sale->patient_id,
                ]);
                return;
            }

            $clinic = Setting::getSettings()->clinic_name ?? 'the clinic';

            if ($patient->contact) {
                $message = SmsTemplate::render('payment_receipt', [
                    '[NAME]'   => $patient->name,
                    '[AMOUNT]' => number_format((float) $sale->total_amount, 2),
                    '[TXN_ID]' => $sale->transaction_id,
                    '[CLINIC]' => $clinic,
                ]);

                if ($message) {
                    (new SmsService())->send(
                        $patient->contact,
                        $message,
                        $patient->id,
                        'payment_receipt'
                    );
                }
            }
            // Patients hear from the clinic by SMS only; email is for the clinic owner.
        } catch (\Throwable $e) {
            Log::warning('Post-commit payment receipt delivery failed.', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ===================== RENDER ===================== */

    private function findOpenVisitSale(bool $lockForUpdate = false): ?Sales
    {
        if (!$this->patientId || $this->purchaseMode === 'direct' || !$this->prescriptionConsultationId || !$this->visitClearanceUuid) {
            return null;
        }

        $query = Sales::query()
            ->where('patient_id', $this->patientId)
            ->where('bill_status', 'open')
            ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('is_refunded', false)
            ->whereHas('clearance', fn ($clearance) => $clearance->where('uuid', $this->visitClearanceUuid))
            ->whereHas('clearance.consultation', fn ($consultation) =>
                $consultation->whereKey($this->prescriptionConsultationId)
            );

        $user = Auth::user();
        if (!$user?->hasAnyRole(['Manager', 'Super Admin'])) {
            $query->where('user_id', $user?->id);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->latest('id')->first();
    }

    public function render()
    {
        $products = Product::with('category')
            ->where(function ($q) {
                $term = $this->productSearchTerm;
                $q->where('name', 'like', '%' . $term . '%')
                  ->orWhere('batch_number', 'like', '%' . $term . '%');
            })
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()))
            ->when($this->selectedCategoryId, fn($q) => $q->where('category_id', $this->selectedCategoryId))
            ->paginate(12);

        // These are Eloquent objects consumed directly by the view. Laravel 13
        // intentionally blocks cached object unserialization by default.
        $categories = Category::orderBy('name')->get();
        $clinicSettings = Setting::getSettings();

        $cartProducts = $this->fetchCartProducts();

        $totalPaid       = $this->getTotalPaid();
        $discountBlocked = $this->discountAmount > 0 && !$this->discountApproved;
        $canCheckout     = $discountBlocked || ($totalPaid > 0 && (
            ($this->isPartPayment && $totalPaid < $this->finalAmount) ||
            $totalPaid >= $this->finalAmount
        ));
        $pendingPrescriptionCartCount = Cart::where('purchased', false)
            ->where('status', 'pending')
            ->whereNotNull('consultation_id')
            ->where('consultation_id', '!=', 0)
            ->distinct()
            ->count('patient_id');
        $approvedDiscountCount = DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_APPROVED)
            ->where('cashier_id', Auth::id())
            ->count();
        $openVisitSale = $this->findOpenVisitSale();

        return view('livewire.pos-component', compact(
            'products', 'categories', 'clinicSettings',
            'cartProducts', 'discountBlocked', 'canCheckout', 'totalPaid',
            'pendingPrescriptionCartCount', 'approvedDiscountCount', 'openVisitSale'
        ))->layout('layouts.secretary.secretary-layout');
    }
}
