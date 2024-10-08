<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Products\ProductResource;
use App\Http\Resources\Products\ProductVariantResource;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;


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
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
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
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);
        }

        // Lọc theo biến thể
        if ($request->has('attribute')) {
            $attributes = $request->attribute;
            $query->whereHas('productVariants', function ($q) use ($attributes) {
                foreach ($attributes as $attribute_id => $value_ids) {
                    $q->whereHas('variantAttributes', function ($subQuery) use ($attribute_id, $value_ids) {
                        $subQuery->where('attribute_id', $attribute_id)
                            ->whereIn('value_id', $value_ids);
                    });
                }
            });
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
    public function store(Request $request)
    {
        // Validate sản phẩm cơ bản
        $validation = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'price_old' => 'nullable|numeric',
                'quantity' => 'required|integer',
                'category_id' => 'nullable|exists:categories,id',
                'brand_id' => 'nullable',
                'promotion' => 'nullable|string',
                'status' => 'nullable|string',
                'images' => 'required|array',
                'images.*' => 'required|file|mimes:jpg,jpeg,png,webp', // Sửa lại để nhận file upload
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

        foreach ($request->file('images') as $imageFile) {
            // Upload ảnh lên Cloudinary
            $uploadedFileUrl = Cloudinary::upload($imageFile->getRealPath())->getSecurePath();

            // Lưu URL của ảnh vào CSDL
            $product->productImages()->create([
                'image_url' => $uploadedFileUrl,
                'is_thumbnail' => $request->input('images.is_thumbnail') ?? false,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Product created successfully',
            'data' => new ProductResource($product)
        ], 200);
    }

    // public function toggleProductAttribute(Request $request, $productId)
    // {
    //     $validation = Validator::make(
    //         $request->all(),
    //         [
    //             'attribute_id' => 'required|exists:attributes,id',
    //             'value_id' => 'required|exists:attribute_values,id',
    //         ]
    //     );

    //     if ($validation->fails()) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Validation Error',
    //             'data' => $validation->errors()
    //         ], 422);
    //     }

    //     $product = Product::find($productId);

    //     if (!$product) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Product not found',
    //             'data' => null
    //         ], 404);
    //     }

    //     // Kiểm tra biến thể dựa trên attribute_id và value_id
    //     $productVariant = $product->productVariants()
    //         ->whereHas('variantAttributes', function ($query) use ($request) {
    //             $query->where('attribute_id', $request->attribute_id)
    //                 ->where('value_id', $request->value_id);
    //         })
    //         ->first();

    //     if ($productVariant) {
    //         // Nếu tồn tại, xóa biến thể
    //         $productVariant->variantAttributes()->where('attribute_id', $request->attribute_id)
    //             ->where('value_id', $request->value_id)
    //             ->delete();

    //         // Nếu không còn thuộc tính nào sau khi xóa, xóa luôn biến thể
    //         if ($productVariant->variantAttributes()->count() == 0) {
    //             $productVariant->delete();
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Product attribute removed successfully',
    //             'data' => new ProductVariantResource($productVariant)
    //         ], 200);
    //     } else {
    //         // Nếu chưa tồn tại, tạo mới biến thể và thêm thuộc tính
    //         $productVariant = $product->productVariants()->create([]);

    //         $productVariant->variantAttributes()->create([
    //             'attribute_id' => $request->attribute_id,
    //             'value_id' => $request->value_id
    //         ]);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Product attribute added successfully',
    //             'data' => new ProductVariantResource($productVariant)
    //         ], 201);
    //     }
    // }


    public function updateVariants(Request $request)
    {
        $product = Product::find($request->product_id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }

        // Lấy các thuộc tính từ request
        $attributes = $request->attribute;

        // Tạo các tổ hợp biến thể từ request
        $combinations = $this->generateCombinations($attributes);

        // Lấy tất cả các biến thể hiện có của sản phẩm
        $existingVariants = $product->productVariants()->with('variantAttributes')->get();

        // Khởi tạo mảng chứa SKU các biến thể từ request
        $requestVariantSkus = [];

        // Duyệt qua các tổ hợp biến thể từ request
        foreach ($combinations as $combination) {
            $skuParts = [];

            foreach ($combination as $attribute) {
                $valueName = AttributeValue::where('id', $attribute['value_id'])->pluck('value')->first();
                $skuParts[] = $valueName;
            }

            // Tạo SKU cho biến thể
            $sku = 'SKU-' . implode('-', $skuParts);
            $requestVariantSkus[] = $sku;

            // Kiểm tra xem biến thể đã tồn tại chưa
            $existingVariant = $existingVariants->firstWhere('sku', $sku);

            if ($existingVariant) {
                // Nếu đã tồn tại, cập nhật biến thể
                $existingVariant->update([
                    'stock' => $request->stock,
                    'price' => $request->price,
                ]);
            } else {
                // Nếu chưa tồn tại, tạo mới biến thể
                $newVariant = $product->productVariants()->create([
                    'sku' => $sku,
                    'stock' => $request->stock,
                    'price' => $request->price,
                ]);

                // Lưu thuộc tính của biến thể
                foreach ($combination as $attribute) {
                    $newVariant->variantAttributes()->create([
                        'attribute_id' => $attribute['attribute_id'],
                        'value_id' => $attribute['value_id']
                    ]);
                }
            }
        }

        // Xóa các biến thể không còn trong request
        foreach ($existingVariants as $existingVariant) {
            if (!in_array($existingVariant->sku, $requestVariantSkus)) {
                $existingVariant->delete();
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Product variants updated successfully',
            'variants' => $requestVariantSkus,
        ], 200);
    }

    // Hàm tạo tổ hợp đơn giản
    private function generateCombinations($attributes)
    {
        $combinations = [[]];

        foreach ($attributes as $attribute) {
            $newCombinations = [];
            foreach ($combinations as $combination) {
                foreach ($attribute['value_ids'] as $value_id) {
                    $newCombinations[] = array_merge($combination, [['attribute_id' => $attribute['attribute_id'], 'value_id' => $value_id]]);
                }
            }
            $combinations = $newCombinations;
        }

        return $combinations;
    }




    // Tạo các dữ liệu cho từng biến thế, ví dụ: stock, price, thumbnail
    public function updateMultipleVariants(Request $request)
    {
        $validation = Validator::make(
            $request->all(),
            [
                'thumbnail.*' => 'required|file|mimes:jpg,jpeg,png', // Validate file upload cho thumbnail
            ]
        );

        if ($validation->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'ErrorValidated',
                'data' => $validation->errors()
            ], 422);
        }

        // Lấy danh sách các biến thể từ request
        $variants = $request->input('variants');

        foreach ($variants as $variantData) {
            // Tìm biến thể theo ID
            $variant = ProductVariant::find($variantData['id']);

            if ($variant) {
                // Kiểm tra xem có file thumbnail upload không
                if ($request->hasFile('thumbnail.' . $variantData['id'])) {
                    // Upload file thumbnail lên Cloudinary
                    $uploadedThumbnailUrl = Cloudinary::upload($request->file('thumbnail.' . $variantData['id'])->getRealPath())->getSecurePath();

                    // Cập nhật thông tin biến thể với URL thumbnail từ Cloudinary
                    $variant->update([
                        'price' => $variantData['price'] ?? $variant->price,
                        'stock' => $variantData['stock'] ?? $variant->stock,
                        'thumbnail' => $uploadedThumbnailUrl, // Lưu URL thumbnail từ Cloudinary
                    ]);
                } else {
                    // Nếu không có file thumbnail mới, chỉ cập nhật giá và stock
                    $variant->update([
                        'price' => $variantData['price'] ?? $variant->price,
                        'stock' => $variantData['stock'] ?? $variant->stock,
                        'thumbnail' => null
                    ]);
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Product variants updated successfully',
        ], 200);
    }

    public function deleteVariant($id)
    {
        // Tìm biến thể theo ID
        $variant = ProductVariant::find($id);

        if (!$variant) {
            return response()->json([
                'status' => false,
                'message' => 'Product variant not found',
            ], 404);
        }

        // Xóa biến thể
        $variant->delete();

        return response()->json([
            'status' => true,
            'message' => 'Product variant deleted successfully',
        ], 200);
    }


    public function updateProductAndVariants(Request $request, $id)
    {
        // Tìm sản phẩm theo ID
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }

        // Cập nhật thông tin sản phẩm
        $product->update([
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'price_old' => $request->price_old,
            'quantity' => $request->quantity,
            'category_id' => $request->category_id,
            'brand_id' => $request->brand_id,
            'promotion' => $request->promotion,
            'status' => $request->status,
        ]);

        // Lấy các biến thể hiện tại của sản phẩm
        $existingVariantIds = $product->productVariants->pluck('id')->toArray();

        // Cập nhật hoặc thêm mới các biến thể
        foreach ($request->variants as $variantData) {
            // Kiểm tra biến thể đã tồn tại chưa (dựa trên SKU hoặc thuộc tính)
            $variant = ProductVariant::find($variantData['id']) ?? new ProductVariant;

            $variant->product_id = $product->id;
            $variant->sku = $variantData['sku'];
            $variant->stock = $variantData['stock'];
            $variant->price = $variantData['price'];
            $variant->save();

            // Xóa các thuộc tính cũ của biến thể và thêm mới
            $variant->variantAttributes()->delete();
            foreach ($variantData['attributes'] as $attribute) {
                $variant->variantAttributes()->create([
                    'attribute_id' => $attribute['attribute_id'],
                    'value_id' => $attribute['value_id']
                ]);
            }

            // Loại bỏ biến thể này khỏi danh sách biến thể cần xóa
            if (isset($variantData['id'])) {
                $existingVariantIds = array_diff($existingVariantIds, [$variantData['id']]);
            }
        }

        // Xóa các biến thể không còn tồn tại trong yêu cầu
        ProductVariant::destroy($existingVariantIds);

        return response()->json([
            'status' => true,
            'message' => 'Product and variants updated successfully',
            'data' => $product->load('productVariants.variantAttributes.attribute'),
        ], 200);
    }









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
    //             'images.*.url' => 'required|string|url',
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

    //     foreach ($request->input('images') as $imageData) {
    //         $product->productImages()->create([
    //             'image_url' => $imageData['url'],
    //             'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
    //         ]);
    //     }

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Success',
    //         'data' => new ProductResource($product)
    //     ], 200);
    // }


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
        $product->increment('view');
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
                // Validation cho sản phẩm
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'price_old' => 'nullable|numeric',
                'quantity' => 'nullable|integer',
                'category_id' => 'nullable|exists:categories,id',
                'brand_id' => 'nullable|exists:brands,id',
                'promotion' => 'nullable|string',
                'status' => 'nullable|string',

                // Validation cho biến thể
                'variants' => 'sometimes|array',
                'variants.*.id' => 'sometimes|exists:product_variants,id',
                'variants.*.stock' => 'required|integer',
                'variants.*.price' => 'required|numeric',

                // Validation cho ảnh
                'images' => 'sometimes|array',
                'images.*.id' => 'sometimes',
                'images.*.file' => 'sometimes|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
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

        // Tìm sản phẩm theo ID
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
                'data' => null
            ], 404);
        }

        // Cập nhật thông tin sản phẩm
        $product->update($request->only(['name', 'description', 'price', 'price_old', 'quantity', 'category_id', 'brand_id', 'promotion', 'status']));

        // Cập nhật biến thể (nếu có)
        if ($request->has('variants')) {
            foreach ($request->variants as $variantData) {
                if (isset($variantData['id'])) {
                    // Cập nhật biến thể đã tồn tại
                    $productVariant = ProductVariant::find($variantData['id']);
                    if ($productVariant) {
                        $productVariant->update([
                            'stock' => $variantData['stock'],
                            'price' => $variantData['price'],
                        ]);
                    }
                } else {
                    // Tạo mới biến thể nếu chưa tồn tại
                    $productVariant = $product->productVariants()->create([
                        'sku' => $variantData['sku'],
                        'stock' => $variantData['stock'],
                        'price' => $variantData['price']
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

        // Cập nhật hoặc thêm mới ảnh sản phẩm
        if ($request->has('images')) {
            $images = $request->input('images', []);

            // Lấy tất cả ID của ảnh có trong request để giữ lại
            $requestImageIds = collect($images)
                ->filter(fn($imageData) => isset($imageData['id']))
                ->pluck('id')
                ->toArray();

            // Xóa các ảnh không có trong request nhưng có trong CSDL
            $existingImages = $product->productImages()->pluck('id')->toArray();
            $imagesToDelete = array_diff($existingImages, $requestImageIds);
            ProductImage::destroy($imagesToDelete); // Xóa ảnh không có trong request

            // Xử lý ảnh mới và ảnh đã tồn tại
            foreach ($images as $key => $imageData) {
                if (isset($imageData['id'])) {
                    // Ảnh đã tồn tại, cập nhật thông tin của nó
                    $productImage = ProductImage::find($imageData['id']);
                    if ($productImage) {
                        $productImage->update([
                            'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
                        ]);
                    }
                } else {
                    // Xử lý ảnh mới, upload lên Cloudinary
                    $imageFile = $request->file("images.$key.file");
                    if ($imageFile instanceof \Illuminate\Http\UploadedFile) {
                        $uploadedFileUrl = Cloudinary::upload($imageFile->getRealPath())->getSecurePath();
                        $product->productImages()->create([
                            'image_url' => $uploadedFileUrl,
                            'is_thumbnail' => $imageData['is_thumbnail'] ?? false,
                        ]);
                    }
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
