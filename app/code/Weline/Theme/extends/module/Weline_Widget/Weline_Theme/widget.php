<?php

declare(strict_types=1);

/**
 * 部件规约文件：Weline_Theme 模块的部件定义（精简模式）
 *
 * 本文件仅列出部件模板路径，元数据（name/type/code/params/position/page_layouts/slots 等）
 * 由各模板 default.phtml 中的 @widget.*、@param 注释提供，运行 php bin/w widget:refresh 时自动收集。
 *
 * 路径规范：Weline_Theme::theme/frontend/widgets/{type}/{code}/default.phtml
 */
return [
    // --- 布局容器 (container) ---
    'Weline_Theme::theme/frontend/widgets/container/header/default.phtml',
    'Weline_Theme::theme/frontend/widgets/container/footer/default.phtml',
    'Weline_Theme::theme/frontend/widgets/container/content/default.phtml',

    // --- 页头 (header / navigation / search) ---
    'Weline_Theme::theme/frontend/widgets/header/logo/default.phtml',
    'Weline_Theme::theme/frontend/widgets/navigation/main-nav/default.phtml',
    'Weline_Theme::theme/frontend/widgets/search/header-search/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/mini-cart-icon/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/account/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/language-switcher/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/currency-switcher/default.phtml',

    // --- 横幅 (banner) ---
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/banner/hero-slider/default.phtml',
        'params' => [
            'slides' => [
                'type' => 'banner_items',
                'label' => '轮播图片',
            ],
        ],
    ],
    'Weline_Theme::theme/frontend/widgets/banner/promo-banner/default.phtml',
    'Weline_Theme::theme/frontend/widgets/banner/ad-banner/default.phtml',

    // --- 商品 (product) ---
    'Weline_Theme::theme/frontend/widgets/product/featured-products/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/new-arrivals/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/bestsellers/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/deals-of-day/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/related-products/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/recently-viewed/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/you-may-like/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/cross-sell/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/up-sell/default.phtml',

    // --- 轮播 (carousel) ---
    'Weline_Theme::theme/frontend/widgets/carousel/product-carousel/default.phtml',

    // --- 分类 (category) ---
    'Weline_Theme::theme/frontend/widgets/category/category-list/default.phtml',
    'Weline_Theme::theme/frontend/widgets/category/category-grid/default.phtml',
    'Weline_Theme::theme/frontend/widgets/navigation/category-menu/default.phtml',
    'Weline_Theme::theme/frontend/widgets/category-filters/default.phtml',

    // --- 侧栏 (sidebar) ---
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-menu/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/mini-cart/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-newsletter/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-ads/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/tags-cloud/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-social/default.phtml',

    // --- 内容块 (content) ---
    'Weline_Theme::theme/frontend/widgets/content/text-block/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/image-text/default.phtml',
    'Weline_Theme::theme/frontend/widgets/video/video-player/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/countdown/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/brand-logos/default.phtml',

    // --- 页脚与订阅 (footer / newsletter / social) ---
    'Weline_Theme::theme/frontend/widgets/footer/footer-links/default.phtml',
    'Weline_Theme::theme/frontend/widgets/newsletter/footer-newsletter/default.phtml',
    'Weline_Theme::theme/frontend/widgets/social/footer-social/default.phtml',
    'Weline_Theme::theme/frontend/widgets/footer/footer-payment/default.phtml',
    'Weline_Theme::theme/frontend/widgets/footer/footer-copyright/default.phtml',
    'Weline_Theme::theme/frontend/widgets/social/social-share/default.phtml',
    'Weline_Theme::theme/frontend/widgets/newsletter/newsletter-popup/default.phtml',

    // --- 评价 / 信任 / FAQ (testimonial / content / faq) ---
    'Weline_Theme::theme/frontend/widgets/testimonial/testimonials/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/trust-badges/default.phtml',
    'Weline_Theme::theme/frontend/widgets/faq/faq-accordion/default.phtml',

    // --- 面包屑 / 搜索 / 分页 ---
    'Weline_Theme::theme/frontend/widgets/breadcrumb/breadcrumb/default.phtml',
    'Weline_Theme::theme/frontend/widgets/search/search-bar/default.phtml',
    'Weline_Theme::theme/frontend/widgets/pagination/pagination/default.phtml',
];
