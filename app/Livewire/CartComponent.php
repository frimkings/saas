<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class CartComponent extends Component
{
    public $patient_id;
    public $selectedProductId;
    public $productQuantity = 1;
    public $productPrice = 0;
    public $cartItems = [];
    public $products = [];

    public function mount($patient_id)
    {
        $this->patient_id = $patient_id;
        $this->loadCart();
    }

    public function loadCart()
    {
        $this->cartItems = Cart::where('patient_id', $this->patient_id)
            ->with('product')
            ->get()
            ->map(function ($cart) {
                return [
                    'id' => $cart->id,
                    'product_id' => $cart->product_id,
                    'name' => $cart->product->name ?? 'N/A',
                    'quantity' => $cart->quantity,
                    'price' => $cart->price,
                    'total' => $cart->total,
                ];
            })
            ->toArray();
    }

    // ✅ Public method for Add to Cart
    public function addToCart()
    {
        $this->validate([
            'selectedProductId' => 'required|exists:products,id',
            'productQuantity' => 'required|integer|min:1',
            'productPrice' => 'required|numeric|min:0',
        ]);

        $cart = Cart::updateOrCreate(
            [
                'patient_id' => $this->patient_id,
                'product_id' => $this->selectedProductId,
            ],
            [
                'dispensed_by' => Auth::id(),
                'quantity' => $this->productQuantity,
                'price' => $this->productPrice,
                'total' => $this->productQuantity * $this->productPrice,
            ]
        );

        // Reload cart items after adding
        $this->loadCart();

        // Reset inputs
        $this->selectedProductId = null;
        $this->productQuantity = 1;
        $this->productPrice = 0;

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Product added to cart successfully.']);
    }

    public function removeFromCart($id)
    {
        Cart::find($id)?->delete();
        $this->loadCart();
    }




 public function updatedSelectedProductId($value)
{
    if ($value) {
        $product = Product::find($value);
        if ($product) {
            $this->productPrice = $product->selling_price;
        }
    }
}
   




  




    public function render()
    {
        $this->products = Product::all();

        return view('livewire.cart-component', [
            'productsList' => $this->products,
            'cartItems' => $this->cartItems,
        ]) ->layout('layouts.secretary.secretary-layout');
    }
}

 




