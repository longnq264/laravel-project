<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuestOrder;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::find($request->product_id);
        $variant = ProductVariant::find($request->variant_id);

        if (!$product || ($request->variant_id && !$variant)) {
            return response()->json(['message' => 'Product or variant not found'], 404);
        }

        if ($request->quantity > ($variant ? $variant->stock : $product->quantity)) {
            return response()->json(['message' => 'Insufficient product quantity'], 400);
        }

        if (Auth::check()) {
            $userId = Auth::id();
            $order = Order::firstOrCreate(
                ['user_id' => $userId, 'status_id' => 1],
                ['total_amount' => 0]
            );

            $orderItem = OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'product_id' => $request->product_id, 'variant_id' => $request->variant_id],
                ['quantity' => DB::raw('quantity + ' . $request->quantity), 'price' => $this->getProductPrice($request->product_id, $request->variant_id)]
            );

            $order->total_amount += $orderItem->price * $request->quantity;
            $order->save();

            return response()->json(['message' => 'Product added to cart successfully', 'data' => $order]);
        } else {
            $cart = session()->get('cart', []);

            $cartExists = false;
            foreach ($cart as &$item) {
                if ($item['product_id'] == $request->product_id && $item['variant_id'] == $request->variant_id) {
                    $item['quantity'] += $request->quantity;
                    $cartExists = true;
                    break;
                }
            }

            if (!$cartExists) {
                $cartItem = [
                    'product_id' => $request->product_id,
                    'variant_id' => $request->variant_id,
                    'quantity' => $request->quantity,
                    'price' => $variant ? $variant->price : $product->price,
                    'product' => $product,
                    'variant' => $variant,
                ];
                $cart[] = $cartItem;
            }

            session()->put('cart', $cart);

            return response()->json(['message' => 'Product added to cart successfully', 'data' => $cart]);
        }
    }

    public function viewCart()
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $order = Order::with(['items.product', 'items.variant'])->where('user_id', $userId)->where('status_id', 1)->first();

            if (!$order) {
                return response()->json(['message' => 'Your cart is user empty'], 404);
            }

            $cartDetails = [
                'id' => $order->id,
                'user_id' => $order->user_id,
                'total_amount' => $order->total_amount,
                'status_id' => $order->status_id,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'order_id' => $item->order_id,
                        'product_id' => $item->product_id,
                        'variant_id' => $item->variant_id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'total_price' => $item->quantity * $item->price, // Tổng giá trị của mục giỏ hàng
                        'product' => [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'description' => $item->product->description,
                            'price' => $item->product->price,
                            'price_old' => $item->product->price_old,
                            'quantity' => $item->product->quantity,
                            'view' => $item->product->view,
                            'category_id' => $item->product->category_id,
                            'brand_id' => $item->product->brand_id,
                            'promotion' => $item->product->promotion,
                            'status' => $item->product->status,
                            'created_at' => $item->product->created_at,
                            'updated_at' => $item->product->updated_at,
                            'image' => $item->product->productImages->first()->image_url ?? null, // Ảnh sản phẩm
                        ],
                        'variant' => $item->variant ? [
                            'id' => $item->variant->id,
                            'product_id' => $item->variant->product_id,
                            'sku' => $item->variant->sku,
                            'stock' => $item->variant->stock,
                            'price' => $item->variant->price,
                            'thumbnail' => $item->variant->thumbnail,
                            'created_at' => $item->variant->created_at,
                            'updated_at' => $item->variant->updated_at,
                        ] : null,
                    ];
                }),
            ];

            return response()->json($cartDetails);
        } else {
            $cart = session()->get('cart', []);

            if (empty($cart)) {
                return response()->json(['message' => 'Your cart session is empty'], 404);
            }

            return response()->json(['message' => 'Success', 'data' => $cart]);
        }
    }

    public function updateCart(Request $request, $itemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        if (Auth::check()) {
            $userId = Auth::id();
            $order = Order::where('user_id', $userId)->where('status_id', 1)->first();

            if (!$order) {
                return response()->json(['message' => 'Your cart is empty'], 404);
            }

            $orderItem = OrderItem::where('id', $itemId)->where('order_id', $order->id)->first();

            if (!$orderItem) {
                return response()->json(['message' => 'Item not found in cart'], 404);
            }

            $product = Product::find($orderItem->product_id);
            $variant = $orderItem->variant_id ? ProductVariant::find($orderItem->variant_id) : null;

            if ($request->quantity > ($variant ? $variant->stock : $product->quantity)) {
                return response()->json(['message' => 'Insufficient product quantity'], 400);
            }

            $order->total_amount -= $orderItem->price * $orderItem->quantity;
            $orderItem->quantity = $request->quantity;
            $orderItem->save();

            $order->total_amount += $orderItem->price * $orderItem->quantity;
            $order->save();

            return response()->json(['message' => 'Cart updated successfully']);
        } else {
            $cart = session()->get('cart', []);

            foreach ($cart as &$item) {
                if ($item['product_id'] == $itemId) {
                    $product = Product::find($item['product_id']);
                    $variant = $item['variant_id'] ? ProductVariant::find($item['variant_id']) : null;

                    if ($request->quantity > ($variant ? $variant->stock : $product->quantity)) {
                        return response()->json(['message' => 'Insufficient product quantity'], 400);
                    }

                    $item['quantity'] = $request->quantity;
                    break;
                }
            }

            session()->put('cart', $cart);

            return response()->json(['message' => 'Cart updated successfully']);
        }
    }

    public function removeFromCart($itemId)
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $order = Order::where('user_id', $userId)->where('status_id', 1)->first();

            if (!$order) {
                return response()->json(['message' => 'Your cart is empty'], 404);
            }

            $orderItem = OrderItem::where('id', $itemId)->where('order_id', $order->id)->first();

            if (!$orderItem) {
                return response()->json(['message' => 'Item not found in cart'], 404);
            }

            $order->total_amount -= $orderItem->price * $orderItem->quantity;
            $orderItem->delete();
            $order->save();

            return response()->json(['message' => 'Product removed from cart successfully']);
        } else {
            $cart = session()->get('cart', []);
            $newCart = [];

            foreach ($cart as $item) {
                if ($item['product_id'] != $itemId) {
                    $newCart[] = $item;
                }
            }

            session()->put('cart', $newCart);

            return response()->json(['message' => 'Product removed from cart successfully']);
        }
    }

    public function clearCart()
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $order = Order::with(['items'])->where('user_id', $userId)->where('status_id', 1)->first();

            if (!$order) {
                return response()->json(['message' => 'Your cart is already empty'], 404);
            }

            foreach ($order->items as $item) {
                $item->delete();
            }

            $order->total_amount = 0;
            $order->save();

            return response()->json(['message' => 'Cart cleared successfully']);
        } else {
            session()->forget('cart');
            return response()->json(['message' => 'Cart cleared successfully']);
        }
    }

    public function checkout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shipping_method' => 'required|string|max:255',
            'payment' => 'required|string|max:255',
            'address_detail' => 'required|string|max:255',
            'ward' => 'required|string|max:255',
            'district' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'phone_number' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        if (Auth::check()) {
            $userId = Auth::id();
            $order = Order::where('user_id', $userId)->where('status_id', 1)->first();

            if (!$order) {
                return response()->json(['message' => 'Your cart is empty'], 404);
            }

            // Update order status to 'confirmed'
            $order->status_id = 2; // Assuming 2 is for confirmed
            $order->shipping_method = $request->shipping_method;
            $order->payment = $request->payment;
            $order->address_detail = $request->address_detail;
            $order->ward = $request->ward;
            $order->district = $request->district;
            $order->city = $request->city;
            $order->save();

            // Clear the session cart if it exists
            session()->forget('cart');

            return response()->json([
                'message' => 'Order has been placed successfully',
                'order' => $order
            ]);
        } else {
            $cart = session()->get('cart', []);

            if (empty($cart)) {
                return response()->json(['message' => 'Your cart is empty'], 404);
            }

            // Create a new order for the guest user
            $order = Order::create([
                'user_id' => null, // Guest user
                'total_amount' => array_sum(array_column($cart, 'price')),
                'status_id' => 2, // Assuming 2 is for confirmed
                'shipping_method' => $request->shipping_method,
                'payment' => $request->payment,
                'address_detail' => $request->address_detail,
                'ward' => $request->ward,
                'district' => $request->district,
                'city' => $request->city,
            ]);

            // Add items to the order
            foreach ($cart as $cartItem) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cartItem['product_id'],
                    'variant_id' => $cartItem['variant_id'],
                    'quantity' => $cartItem['quantity'],
                    'price' => $cartItem['price'],
                ]);
            }

            // Save guest order details
            GuestOrder::create([
                'order_id' => $order->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'address_detail' => $request->address_detail,
                'ward' => $request->ward,
                'district' => $request->district,
                'city' => $request->city,
            ]);

            // Clear the session cart
            session()->forget('cart');

            return response()->json([
                'message' => 'Order has been placed successfully',
                'order' => $order
            ]);
        }
    }

    private function getProductPrice($productId, $variantId = null)
    {
        if ($variantId) {
            $variant = ProductVariant::find($variantId);
            return $variant ? $variant->price : 0;
        }

        $product = Product::find($productId);
        return $product ? $product->price : 0;
    }
}
