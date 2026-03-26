<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use WeShop\Catalog\Service\CategoryService;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;

class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Frontend::templates/Index/index.phtml';

    protected ?string $layoutType = 'homepage';

    public function __construct(
        private readonly ProductService $productService,
        private readonly CategoryService $categoryService
    ) {
    }

    public function index(): string
    {
        $categories = $this->categoryService->getCategoryTree(0);
        $formattedCategories = [];
        foreach ($categories as $category) {
            $formattedCategories[] = [
                'category_id' => $category['category_id'] ?? 0,
                'name' => $category['name'] ?? '',
                'url' => $this->getUrl('weshop/product/list', ['category_id' => $category['category_id'] ?? 0]),
                'image' => $category['image'] ?? '',
                'children' => $category['children'] ?? [],
            ];
        }

        $recommendedResult = $this->productService->getProducts([
            'status' => 1,
            'order_by' => Product::schema_fields_ID,
            'order_dir' => 'DESC',
        ], 1, 12);

        $recommendedProducts = [];
        foreach ($recommendedResult['items'] as $product) {
            $recommendedProducts[] = [
                'product_id' => $product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[Product::schema_fields_name] ?? '',
                'short_description' => $product['short_description'] ?? $product[Product::schema_fields_short_description] ?? '',
                'price' => $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[Product::schema_fields_sku] ?? '',
                'in_stock' => ($product['stock'] ?? $product[Product::schema_fields_stock] ?? 0) > 0,
            ];
        }

        $dealsResult = $this->productService->getProducts([
            'status' => 1,
            'order_by' => 'price',
            'order_dir' => 'ASC',
        ], 1, 8);

        $deals = [];
        foreach ($dealsResult['items'] as $product) {
            $deals[] = [
                'product_id' => $product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[Product::schema_fields_name] ?? '',
                'price' => $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[Product::schema_fields_sku] ?? '',
            ];
        }

        $bestsellersResult = $this->productService->getProducts([
            'status' => 1,
            'order_by' => Product::schema_fields_ID,
            'order_dir' => 'DESC',
        ], 1, 8);

        $bestsellers = [];
        foreach ($bestsellersResult['items'] as $product) {
            $bestsellers[] = [
                'product_id' => $product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[Product::schema_fields_name] ?? '',
                'price' => $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[Product::schema_fields_sku] ?? '',
            ];
        }

        $banners = [
            [
                'title' => __('新品上市'),
                'subtitle' => __('发现最新潮流'),
                'image' => $this->getStaticUrl('assets/images/banner-1.jpg'),
                'link' => $this->getUrl('weshop/product/list'),
            ],
            [
                'title' => __('限时特惠'),
                'subtitle' => __('超值优惠等你来'),
                'image' => $this->getStaticUrl('assets/images/banner-2.jpg'),
                'link' => $this->getUrl('weshop/promotion'),
            ],
            [
                'title' => __('品质保证'),
                'subtitle' => __('正品保障，放心购物'),
                'image' => $this->getStaticUrl('assets/images/banner-3.jpg'),
                'link' => $this->getUrl('weshop'),
            ],
        ];

        $this->assign('categories', $formattedCategories);
        $this->assign('recommended_products', $recommendedProducts);
        $this->assign('deals', $deals);
        $this->assign('bestsellers', $bestsellers);
        $this->assign('banners', $banners);
        $this->assign('title', __('首页'));

        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
