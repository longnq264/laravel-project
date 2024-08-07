<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'productImages', 'productVariants.attributes']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%');
        }

        if ($request->has('category_id')) {
            $category = Category::find($request->category_id);
            if ($category) {
                $categoryIds = $category->allChildrenIds();
                $categoryIds[] = $category->id;
                $query->whereIn('category_id', $categoryIds);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Category not found',
                    'data' => []
                ], 404);
            }
        }

        // if ($request->has('category_id')) {
        //     $query->where('category_id', $request->category_id);
        // }
        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('sort_by')) {
            $sortBy = $request->sort_by;
            $sortOrder = $request->sort_order ?? 'asc';
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->get();

        return response()->json([
            'status' => true,
            'message' => 'Success get products',
            'data' => ProductResource::collection($products)
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    // public function store(Request $request)
    // {
    //     // Validate sản phẩm và các biến thể
    //     $validation = Validator::make(
    //         $request->all(),
    //         [
    //             'name' => 'required|string|max:255',
    //             'description' => 'nullable|string',
    //             'price' => 'required|numeric',
    //             'price_old' => 'nullable|numeric',
    //             'quantity' => 'required|integer',
    //             'category_id' => 'nullable|exists:categories,id',
    //             'brand_id' => 'nullable|exists:brands,id',
    //             'promotion' => 'nullable|string',
    //             'status' => 'nullable|string',
    //             'variants' => 'required|array',
    //             'variants.*.sku' => 'required|string|max:50',
    //             'variants.*.stock' => 'required|integer',
    //             'variants.*.price' => 'nullable|numeric',
    //             'variants.*.attributes' => 'required|array',
    //             'variants.*.attributes.*.attribute_id' => 'required|exists:attributes,id',
    //             'variants.*.attributes.*.value_id' => 'required|exists:attribute_values,id',
    //             'images' => 'required|array',
    //             'images.*.file' => 'required|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
    //             'images.*.is_thumbnail' => 'nullable|boolean'
    //         ]
    //     );

    //     if ($validation->fails()) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'ErrorValidated',
    //             'data' => $validation->errors()
    //         ], 422);
    //     }

    //     $product = Product::create($request->only(['name', 'description', 'price', 'price_old', 'quantity', 'category_id', 'brand_id', 'promotion', 'status']));

    //     foreach ($request->variants as $variantData) {
    //         $productVariant = $product->productVariants()->create([
    //             'sku' => $variantData['sku'],
    //             'stock' => $variantData['stock'],
    //             'price' => $variantData['price'],
    //             'thumbnail' => $variantData['thumbnail'] ?? null
    //         ]);

    //         foreach ($variantData['attributes'] as $attribute) {
    //             $productVariant->variantAttributes()->create([
    //                 'attribute_id' => $attribute['attribute_id'],
    //                 'value_id' => $attribute['value_id']
    //             ]);
    //         }
    //     }

    //     foreach ($request->file('images') as $imageData) {
    //         $path = $imageData['file']->store('product_images', 'public');

    //         $product->productImages()->create([
    //             'image_url' => $path,
    //             'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
    //         ]);
    //     }

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Success',
    //         'data' => new ProductResource($product)
    //     ], 200);
    // }

    public function store(Request $request)
    {
        // Validate sản phẩm và các biến thể
        $validation = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'price_old' => 'nullable|numeric',
                'quantity' => 'required|integer',
                'category_id' => 'nullable|exists:categories,id',
                'brand_id' => 'nullable|exists:brands,id',
                'promotion' => 'nullable|string',
                'status' => 'nullable|string',
                'variants' => 'required|array',
                'variants.*.sku' => 'required|string|max:50',
                'variants.*.stock' => 'required|integer',
                'variants.*.price' => 'nullable|numeric',
                'variants.*.attributes' => 'required|array',
                'variants.*.attributes.*.attribute_id' => 'required|exists:attributes,id',
                'variants.*.attributes.*.value_id' => 'required|exists:attribute_values,id',
                'images' => 'required|array',
                'images.*.url' => 'required|string|url',
                'images.*.is_thumbnail' => 'nullable|boolean'
            ]
        );

        if ($validation->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'ErrorValidated',
                'data' => $validation->errors()
            ], 422);
        }

        $product = Product::create($request->only(['name', 'description', 'price', 'price_old', 'quantity', 'category_id', 'brand_id', 'promotion', 'status']));

        foreach ($request->variants as $variantData) {
            $productVariant = $product->productVariants()->create([
                'sku' => $variantData['sku'],
                'stock' => $variantData['stock'],
                'price' => $variantData['price'],
                'thumbnail' => $variantData['thumbnail'] ?? null
            ]);

            foreach ($variantData['attributes'] as $attribute) {
                $productVariant->variantAttributes()->create([
                    'attribute_id' => $attribute['attribute_id'],
                    'value_id' => $attribute['value_id']
                ]);
            }
        }

        foreach ($request->input('images') as $imageData) {
            $product->productImages()->create([
                'image_url' => $imageData['url'],
                'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => new ProductResource($product)
        ], 200);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::with(['category', 'brand', 'productImages', 'productVariants.attributes'])->find($id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
                'data' => null
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Success get product details',
            'data' => new ProductResource($product)
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validation = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'price_old' => 'nullable|numeric',
                'quantity' => 'required|integer',
                'category_id' => 'nullable|exists:categories,id',
                'brand_id' => 'nullable|exists:brands,id',
                'promotion' => 'nullable|string',
                'status' => 'nullable|string',
                'variants' => 'sometimes|array',
                'variants.*.id' => 'sometimes|exists:product_variants,id',
                'variants.*.sku' => 'required|string|max:50',
                'variants.*.stock' => 'required|integer',
                'variants.*.price' => 'nullable|numeric',
                'variants.*.attributes' => 'required|array',
                'variants.*.attributes.*.attribute_id' => 'required|exists:attributes,id',
                'variants.*.attributes.*.value_id' => 'required|exists:attribute_values,id',
                'images' => 'sometimes|array',
                'images.*.id' => 'sometimes|exists:product_images,id',
                'images.*.file' => 'sometimes|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'images.*.is_thumbnail' => 'nullable|boolean'
            ]
        );

        if ($validation->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'ErrorValidated',
                'data' => $validation->errors()
            ], 422);
        }

        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
                'data' => null
            ], 404);
        }

        $product->update($request->only(['name', 'description', 'price', 'price_old', 'quantity', 'category_id', 'brand_id', 'promotion', 'status']));

        if ($request->has('variants')) {
            foreach ($request->variants as $variantData) {
                if (isset($variantData['id'])) {
                    $productVariant = ProductVariant::find($variantData['id']);
                    if ($productVariant) {
                        $productVariant->update([
                            'sku' => $variantData['sku'],
                            'stock' => $variantData['stock'],
                            'price' => $variantData['price'],
                            'thumbnail' => $variantData['thumbnail'] ?? null
                        ]);

                        $productVariant->variantAttributes()->delete();
                        foreach ($variantData['attributes'] as $attribute) {
                            $productVariant->variantAttributes()->create([
                                'attribute_id' => $attribute['attribute_id'],
                                'value_id' => $attribute['value_id']
                            ]);
                        }
                    }
                } else {
                    $productVariant = $product->productVariants()->create([
                        'sku' => $variantData['sku'],
                        'stock' => $variantData['stock'],
                        'price' => $variantData['price'],
                        'thumbnail' => $variantData['thumbnail'] ?? null
                    ]);

                    foreach ($variantData['attributes'] as $attribute) {
                        $productVariant->variantAttributes()->create([
                            'attribute_id' => $attribute['attribute_id'],
                            'value_id' => $attribute['value_id']
                        ]);
                    }
                }
            }
        }

        if ($request->has('images')) {
            foreach ($request->images as $imageData) {
                if (isset($imageData['id'])) {
                    $productImage = ProductImage::find($imageData['id']);
                    if ($productImage) {
                        if (isset($imageData['file'])) {
                            Storage::disk('public')->delete($productImage->image_url);
                            $path = $imageData['file']->store('product_images', 'public');
                            $productImage->update([
                                'image_url' => $path,
                                'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
                            ]);
                        } else {
                            $productImage->update([
                                'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
                            ]);
                        }
                    }
                } else {
                    $path = $imageData['file']->store('product_images', 'public');
                    $product->productImages()->create([
                        'image_url' => $path,
                        'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
                    ]);
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Product updated successfully',
            'data' => new ProductResource($product)
        ], 200);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $product = Product::with('productVariants.variantAttributes')->find($id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
                'data' => []
            ], 404);
        }

        foreach ($product->productVariants as $variant) {
            foreach ($variant->variantAttributes as $attribute) {
                $attribute->delete();
            }
            $variant->delete();
        }
        $product->delete();

        return response()->json([
            'status' => true,
            'message' => 'Product deleted successfully',
            'data' => new ProductResource($product)
        ], 200);
    }

    /**
     * Restore the specified resource from storage.
     */
    public function restore(string $id)
    {
        $product = Product::withTrashed()->with(['productVariants' => function ($query) {
            $query->withTrashed()->with(['variantAttributes' => function ($query) {
                $query->withTrashed();
            }]);
        }])->find($id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
                'data' => []
            ], 404);
        }
        $product->restore();

        foreach ($product->productVariants as $variant) {
            $variant->restore();

            foreach ($variant->variantAttributes as $attribute) {
                $attribute->restore();
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Product restored successfully',
            'data' => new ProductResource($product)
        ], 200);
    }
}
