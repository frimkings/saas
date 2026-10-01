<?php

namespace App\Livewire\Optical;

use App\Models\OpticalProduct;
use App\Support\PaymentMethods;
use App\Models\Sales;
use App\Models\SaleItem;
use App\Models\PaymentTransaction;
use App\Services\ClinicAccessService;
use App\Services\OpticalProductInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class OpticalPosComponent extends Component
{
    public $searchTerm = '';
    public $cart = [];
    public $paymentMethod = '';
    public ?int $lastSaleId = null;
    public string $customerName = '';
    public string $customerPhone = '';
    public string $discount = '';

    public function mount(): void
    {
        $this->paymentMethod = PaymentMethods::first(PaymentMethods::OPTICAL);
    }

    private function assertSoldIndividually(OpticalProduct $product): void
    {
        if ($product->isPairOnlyLens()) {
            throw ValidationException::withMessages(['cart' => 'Progressive and bifocal lenses are sold as a pair (right and left). Create a lens order instead.']);
        }
    }

    public function addToCart($productId)
    {
        if (is_string($productId) && str_starts_with($productId, 'o:')) {
            $id = (int) substr($productId, 2);
            $product = OpticalProduct::where('is_active', true)->with('stocks')->findOrFail($id);
            $this->assertSoldIndividually($product);
            $key = 'o:'.$id;
            if (app(OpticalProductInventoryService::class)->available($product) <= ($this->cart[$key]['qty'] ?? 0)) {
                throw ValidationException::withMessages(['cart' => 'Insufficient stock for '.$product->name.'.']);
            }
            if (isset($this->cart[$key])) $this->cart[$key]['qty']++;
            else $this->cart[$key] = ['id' => $id, 'name' => $product->name, 'price' => (float) $product->selling_price, 'qty' => 1];
            return;
        }
        abort(404);
    }

    public function removeFromCart($productId)
    {
        unset($this->cart[$productId]);
    }

    public function completeSale()
    {
        if (empty($this->cart)) throw ValidationException::withMessages(['cart' => 'Cart is empty.']);
        if (! PaymentMethods::isActive(PaymentMethods::OPTICAL, $this->paymentMethod)) {
            throw ValidationException::withMessages(['paymentMethod' => 'Choose one of the payment methods shown.']);
        }
        $this->validate([
            'customerName' => 'nullable|string|max:255',
            'customerPhone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]*$/'],
            'discount' => 'nullable|numeric|min:0',
        ], ['customerPhone.regex' => 'Enter a valid phone number.']);
        app(ClinicAccessService::class)->assertWritable('optical');
        $this->lastSaleId = DB::transaction(function () {
            $lines = [];
            $total = 0;
            $profit = 0;
            foreach ($this->cart as $id => $item) {
                abort_unless(is_string($id) && str_starts_with($id, 'o:'), 422);
                $product = OpticalProduct::where('is_active', true)->with('stocks')->findOrFail((int) substr($id, 2));
                $this->assertSoldIndividually($product);
                $quantity = (int) $item['qty'];
                $available = app(OpticalProductInventoryService::class)->available($product); // excludes lenses held for orders
                if ($quantity < 1 || $available < $quantity) {
                    throw ValidationException::withMessages(['cart' => 'Insufficient stock for '.$product->name.'.']);
                }
                $price = (float) $product->selling_price;
                $total += $price * $quantity;
                $profit += ($price - (float) $product->cost_price) * $quantity;
                $lines[] = [$product, $quantity, $price];
            }
            $discount = $this->checkedDiscount($total);
            $total = round($total - $discount, 2);
            $sale = Sales::create([
                'business_line' => 'optical',
                'user_id' => auth()->id(),
                'transaction_id' => 'OPOS-' . Str::upper(Str::random(14)),
                'customer_name' => trim($this->customerName) ?: 'Walk-in',
                'customer_phone' => trim($this->customerPhone) ?: null,
                'total_amount' => $total,
                'amount_paid' => $total,
                'payment_status' => 'paid',
                'discount_type' => $discount > 0 ? 'fixed' : null,
                'discount_value' => $discount > 0 ? $discount : null,
                'discount_amount' => $discount,
                'discount_approved_by' => $discount > 0 ? auth()->id() : null,
                'profit' => round($profit - $discount, 2),
            ]);
            foreach ($lines as [$product, $quantity, $price]) {
                app(OpticalProductInventoryService::class)->decrease($product, $quantity, 'Optical POS sale #'.$sale->id);
                SaleItem::create([
                    'sale_id' => $sale->id, 'product_id' => null,
                    'optical_product_id' => $product->id,
                    'prescribed_quantity' => 0, 'dispensed_quantity' => $quantity,
                    'selling_price' => $price, 'subtotal' => round($price * $quantity, 2),
                ]);
            }
            PaymentTransaction::create([
                'sale_id' => $sale->id, 'amount' => round($total, 2),
                'payment_method' => $this->paymentMethod,
                'collected_by' => auth()->id(),
            ]);
            return $sale->id;
        });
        $this->reset(['cart', 'customerName', 'customerPhone', 'discount']);
        session()->flash('success', 'Retail sale recorded successfully.');
    }

    /**
     * Discount in money off the whole sale. Staff may give up to the shop limit; a manager
     * can give more.
     */
    private function checkedDiscount(float $subtotal): float
    {
        $discount = round((float) ($this->discount ?: 0), 2);
        if ($discount <= 0) return 0.0;
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the sale.']);
        }
        $limit = \App\Models\OpticalSetting::posMaxDiscountPercent();
        if ($discount > round($subtotal * $limit / 100, 2) && ! auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])) {
            throw ValidationException::withMessages(['discount' => "Discounts over {$limit}% need a manager."]);
        }
        return $discount;
    }

    public function render()
    {
        $opticalProducts = OpticalProduct::with(['category', 'stocks'])->where('is_active', true)->soldIndividually()
            ->when(trim($this->searchTerm) !== '', fn ($q) => $q->where(fn ($search) => $search
                ->where('name', 'like', '%'.trim($this->searchTerm).'%')
                ->orWhere('sku', 'like', '%'.trim($this->searchTerm).'%')))
            ->orderBy('name')->take(12)->get();
        $products = $opticalProducts;

        $subtotal = array_sum(array_map(fn($item) => $item['price'] * $item['qty'], $this->cart));
        $discount = min($subtotal, max(0, round((float) (is_numeric($this->discount) ? $this->discount : 0), 2)));

        return view('livewire.optical.optical-pos-component', [
            'products' => $products,
            'subtotal' => $subtotal,
            'discountShown' => $discount,
            'total' => $subtotal - $discount,
            'discountLimit' => \App\Models\OpticalSetting::posMaxDiscountPercent(),
            'methods' => PaymentMethods::active(PaymentMethods::OPTICAL),
        ])->layout('layouts.optical');
    }

}
