<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：产品模块的布局提供者 - 实现 LayoutProviderInterface
 */

namespace WeShop\Product\Extends\Weline_Layout;

use WeShop\Catalog\Model\Category;
use WeShop\Product\Helper\ProductLayoutScanner;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductLayout;
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Layout\Api\LayoutProviderInterface;

class ProductLayoutProvider implements LayoutProviderInterface
{
    private const CACHE_KEY_PREFIX = 'weshop_product_layout_';
    private const CACHE_TTL = 3600;

    private ProductLayoutService $layoutService;

    public function __construct(
        ProductLayoutService $layoutService
    ) {
        $this->layoutService = $layoutService;
    }
    
    /**
     * 获取缓存池
     */
    private function getCache(): CachePoolInterface
    {
        return w_cache('product');
    }

    /**
     * @inheritDoc
     */
    public function getModuleCode(): string
    {
        return 'WeShop_Product';
    }

    /**
     * @inheritDoc
     */
    public function getLayoutTypes(): array
    {
        return [
            'product' => [
                'name' => '产品详情页布局',
                'description' => '用于商品详情页的 Theme 布局；产品自身未设置时可从分类默认商品布局继承'
            ],
            'product_list' => [
                'name' => '产品列表布局',
                'description' => '用于产品列表页面的布局，支持网格、列表等多种展示方式'
            ],
            'product_detail' => [
                'name' => '产品详情布局',
                'description' => '用于产品详情页面的布局，支持标准、画廊等多种展示方式'
            ],
            'category' => [
                'name' => '分类页布局',
                'description' => '用于产品分类页面的布局'
            ],
            'category_product_default' => [
                'name' => '分类下商品默认布局',
                'description' => '分类下商品没有产品专属布局时使用的商品详情页布局'
            ],
            'product_widget' => [
                'name' => '产品组件布局',
                'description' => '用于推荐产品、猜你喜欢等组件的布局'
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getLayoutOptions(string $layoutType): array
    {
        // 先获取默认布局选项
        $defaultOptions = [
            'product_list' => [
                'grid' => [
                    'name' => '网格布局',
                    'description' => '以网格形式展示产品，适合图片为主的展示',
                    'template' => 'WeShop_Product::Frontend/Product/list-grid.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/list-grid.png',
                    'columns' => 4
                ],
                'list' => [
                    'name' => '列表布局',
                    'description' => '以列表形式展示产品，适合展示更多详情',
                    'template' => 'WeShop_Product::Frontend/Product/list-list.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/list-list.png'
                ],
                'compact' => [
                    'name' => '紧凑布局',
                    'description' => '紧凑的网格布局，每行展示更多产品',
                    'template' => 'WeShop_Product::Frontend/Product/list-compact.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/list-compact.png',
                    'columns' => 6
                ]
            ],
            'product' => [
                'default' => [
                    'name' => '默认商品详情布局',
                    'description' => 'Theme 默认商品详情页布局',
                    'template' => 'Weline_Theme::theme/frontend/layouts/product/default.phtml',
                    'preview_image' => ''
                ]
            ],
            'product_detail' => [
                'standard' => [
                    'name' => '标准布局',
                    'description' => '经典的左图右信息布局',
                    'template' => 'WeShop_Product::Frontend/Product/detail-standard.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/detail-standard.png'
                ],
                'gallery' => [
                    'name' => '画廊布局',
                    'description' => '大图轮播式布局，突出产品图片',
                    'template' => 'WeShop_Product::Frontend/Product/detail-gallery.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/detail-gallery.png'
                ],
                'full_width' => [
                    'name' => '全宽布局',
                    'description' => '全宽展示，适合大屏幕',
                    'template' => 'WeShop_Product::Frontend/Product/detail-fullwidth.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/detail-fullwidth.png'
                ]
            ],
            'category' => [
                'sidebar_left' => [
                    'name' => '左侧边栏',
                    'description' => '左侧显示筛选器和子分类',
                    'template' => 'WeShop_Product::Frontend/Category/sidebar-left.phtml'
                ],
                'sidebar_right' => [
                    'name' => '右侧边栏',
                    'description' => '右侧显示筛选器和子分类',
                    'template' => 'WeShop_Product::Frontend/Category/sidebar-right.phtml'
                ],
                'no_sidebar' => [
                    'name' => '无侧边栏',
                    'description' => '全宽产品列表，顶部筛选器',
                    'template' => 'WeShop_Product::Frontend/Category/no-sidebar.phtml'
                ]
            ],
            'category_product_default' => [
                'default' => [
                    'name' => '默认商品详情布局',
                    'description' => '分类下商品未设置产品专属布局时使用默认商品详情布局',
                    'template' => 'Weline_Theme::theme/frontend/layouts/product/default.phtml',
                    'preview_image' => ''
                ]
            ],
            'product_widget' => [
                'carousel' => [
                    'name' => '轮播组件',
                    'description' => '产品轮播展示',
                    'template' => 'WeShop_Product::Widget/carousel.phtml'
                ],
                'grid_small' => [
                    'name' => '小网格组件',
                    'description' => '小尺寸网格展示',
                    'template' => 'WeShop_Product::Widget/grid-small.phtml'
                ],
                'featured' => [
                    'name' => '特色产品组件',
                    'description' => '突出展示特色产品',
                    'template' => 'WeShop_Product::Widget/featured.phtml'
                ]
            ]
        ];

        $optionLayoutType = match ($layoutType) {
            ProductLayout::LAYOUT_TYPE_PRODUCT_DETAIL,
            ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT => ProductLayout::LAYOUT_TYPE_PRODUCT,
            default => $layoutType,
        };

        $options = $defaultOptions[$layoutType] ?? ($defaultOptions[$optionLayoutType] ?? []);

        // 扫描产品模块的专属布局文件
        $productLayouts = ProductLayoutScanner::scanProductLayouts($optionLayoutType);
        
        // 合并产品专属布局和默认布局（产品布局优先）
        $options = ProductLayoutScanner::mergeLayoutOptions($productLayouts, $options);

        return $options;
    }

    /**
     * @inheritDoc
     */
    public function applyLayout(string $layoutType, string $layoutCode, mixed $entity): bool
    {
        // 如果entity是Product对象，保存到数据库
        if ($entity instanceof Product && $entity->getId()) {
            $this->layoutService->applyProductLayout(
                $entity->getId(),
                $layoutType,
                $layoutCode
            );
        }
        if ($entity instanceof Category && $entity->getId()) {
            if ($layoutType === ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT) {
                $this->layoutService->applyCategoryProductDefaultLayout($entity->getId(), $layoutCode);
            } else {
                $this->layoutService->applyCategoryLayout($entity->getId(), $layoutCode);
            }
        }

        // 将布局配置保存到缓存
        $cacheKey = $this->getCacheKey($layoutType, $entity);
        $this->getCache()->set($cacheKey, $layoutCode, self::CACHE_TTL);
        
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getCurrentLayout(string $layoutType, mixed $entity): ?string
    {
        // 如果entity是Product对象，优先从数据库读取
        if ($entity instanceof Product && $entity->getId()) {
            $productLayout = $this->layoutService->getProductLayout($entity->getId(), $layoutType);
            if ($productLayout) {
                // 同时更新缓存
                $cacheKey = $this->getCacheKey($layoutType, $entity);
                $this->getCache()->set($cacheKey, $productLayout, self::CACHE_TTL);
                return $productLayout;
            }
        }

        // 从缓存读取
        if ($entity instanceof Category && $entity->getId()) {
            $categoryLayout = $layoutType === ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT
                ? $this->layoutService->resolveCategoryProductDefaultLayoutOption($entity->getId())
                : $this->layoutService->resolveCategoryLayoutOption($entity->getId(), ProductLayout::LAYOUT_TYPE_CATEGORY);
            if ($categoryLayout) {
                $cacheKey = $this->getCacheKey($layoutType, $entity);
                $this->getCache()->set($cacheKey, $categoryLayout, self::CACHE_TTL);
                return $categoryLayout;
            }
        }

        $cacheKey = $this->getCacheKey($layoutType, $entity);
        $layout = $this->getCache()->get($cacheKey);
        
        return $layout ?: null;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultLayout(string $layoutType): string
    {
        $defaults = [
            'product_list' => 'grid',
            'product' => 'default',
            'product_detail' => 'default',
            'category' => 'default',
            'category_product_default' => 'default',
            'product_widget' => 'carousel'
        ];
        
        return $defaults[$layoutType] ?? 'default';
    }

    /**
     * @inheritDoc
     */
    public function onLayoutSwitch(string $layoutType, string $oldLayout, string $newLayout): void
    {
        // 布局切换时可以在这里实现更复杂的缓存清理逻辑
        // 例如：清除所有产品列表页缓存
        
        // 记录日志
        w_log_info("产品布局切换: {layout_type} 从 '{old_layout}' 切换到 '{new_layout}'", [
            'layout_type' => $layoutType,
            'old_layout' => $oldLayout,
            'new_layout' => $newLayout
        ]);
    }

    /**
     * 生成缓存键
     */
    protected function getCacheKey(string $layoutType, mixed $entity): string
    {
        $entityId = '';
        if (is_object($entity) && method_exists($entity, 'getId')) {
            $entityId = '_' . $entity->getId();
        } elseif (is_scalar($entity)) {
            $entityId = '_' . $entity;
        }
        
        return self::CACHE_KEY_PREFIX . $layoutType . $entityId;
    }
}

