<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/Service/TextileHeritageCatalog.php';

use Weline\Theme\Service\TextileHeritageCatalog;

$textileHeritageConfig = TextileHeritageCatalog::widgetConfig();

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
    'Weline_Theme::theme/frontend/widgets/navigation/all-menu/default.phtml' => [
        'params' => [
            'menu_tree' => [
                'type' => 'all_menu_tree',
                'label' => '导航树',
            ],
        ],
    ],
    'Weline_Theme::theme/frontend/widgets/search/header-search/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/mini-cart-icon/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/account/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/language-switcher/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/currency-switcher/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/help-center-link/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/order-tracking-link/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/notice-right-link/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/header-policy-links/default.phtml',
    'Weline_Theme::theme/frontend/widgets/header/top-bar/default.phtml',

    // --- 横幅 (banner) ---
    'Weline_Theme::theme/frontend/widgets/banner/hero-banner/default.phtml',
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/banner/hero-slider/default.phtml',
        'params' => [
            'slides' => [
                'type' => 'banner_items',
                'label' => '轮播图片',
                'description' => '图片数组，每项包含 image、title、subtitle、link、button_text',
            ],
        ],
    ],
    'Weline_Theme::theme/frontend/widgets/banner/promo-banner/default.phtml',
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/banner/ad-banner/default.phtml',
        'params' => [
            'image' => [
                'type' => 'media_image',
                'label' => '广告图片',
                'media_options' => [
                    'default_directory' => 'banner',
                    'aspect_ratio' => '1920/150',
                    'recommend_width' => '1920',
                    'recommend_height' => '150',
                    'aspect_ratio_tolerance' => '0.1',
                ],
            ],
        ],
    ],

    // --- 商品 (product) ---
    'Weline_Theme::theme/frontend/widgets/product/featured-products/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/new-arrivals/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/bestsellers/default.phtml',
    'Weline_Theme::theme/frontend/widgets/product/deals-of-day/default.phtml',
    // you-may-like：店面默认由 Weline_Product 经 default_injections 注入；Theme 演示模板保留供编辑器拖拽参考但不再注册重复 code
    'Weline_Theme::theme/frontend/widgets/product/up-sell/default.phtml',

    // --- 轮播 (carousel) ---
    'Weline_Theme::theme/frontend/widgets/carousel/product-carousel/default.phtml',

    // --- 分类 (category) ---
    'Weline_Theme::theme/frontend/widgets/category/category-list/default.phtml',
    'Weline_Theme::theme/frontend/widgets/category/category-grid/default.phtml',
    'Weline_Theme::theme/frontend/widgets/navigation/category-menu/default.phtml',

    // --- 侧栏 (sidebar) ---
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-menu/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/mini-cart/default.phtml',
    // sidebar-newsletter：已迁至 Weline_Newsletter（D9/D10 同发布，防双注册）
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-ads/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/tags-cloud/default.phtml',
    'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-social/default.phtml',

    // --- 通用建站区块（所有页面；配置与样式由 Theme 统一提供） ---
    'Weline_Theme::theme/frontend/widgets/content/section-heading/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/button-group/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/spacer-divider/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/single-image/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/card-grid/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/image-gallery/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/feature-list/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/stat-grid/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/team-grid/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/step-list/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/pricing-table/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/contact-info/default.phtml',
    'Weline_Theme::theme/frontend/widgets/container/columns/default.phtml',

    // --- 内容块 (content) ---
    'Weline_Theme::theme/frontend/widgets/content/text-block/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/image-text/default.phtml',
    'Weline_Theme::theme/frontend/widgets/video/video-player/default.phtml',
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/video/video-carousel/default.phtml',
        'params' => [
            'items' => [
                'type' => 'video_carousel_items',
                'label' => '视频列表',
                'description' => '可排序多视频项：平台、地址、作者、简介与关联商品',
            ],
        ],
    ],

    'Weline_Theme::theme/frontend/widgets/content/countdown/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/brand-logos/default.phtml',
    'textile-heritage' => [
        'name' => '织艺谱系',
        'description' => '可复用的真实织绣馆藏目录；可在主题编辑器选用，不再注入默认首页品牌槽。',
        'type' => 'content',
        'code' => 'textile-heritage',
        'area' => 'frontend',
        'template' => 'Weline_Theme::theme/frontend/widgets/content/textile-heritage/default.phtml',
        'page_layouts' => ['homepage', 'cms_page'],
        'position' => ['content'],
        'supports' => [
            'layout-homepage-brands',
            'layout-cms-content',
            'layout-default-content',
            'brand-list',
            'brands',
            'textile-heritage',
        ],
        // placement=manual：默认首页品牌槽由布局直嵌 brand-logos；本部件仅编辑器/CMS 选用。
        'placement' => 'manual',
        'params' => [
            'title' => [
                'default' => TextileHeritageCatalog::TITLE,
                'type' => 'string',
                'label' => '标题',
            ],
            'brands' => [
                'default' => $textileHeritageConfig['brands'],
                'type' => 'brand_logo_items',
                'label' => '谱系项',
                'description' => '每项包含名称、说明、真实图片、搜索链接与素材来源',
            ],
            'columns' => [
                'default' => '6',
                'type' => 'select',
                'label' => '每行列数',
                'options' => [
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                    '8' => '8列',
                ],
            ],
            'layout' => [
                'default' => 'grid',
                'type' => 'select',
                'label' => '布局',
                'options' => [
                    'grid' => '网格',
                    'carousel' => '横向滚动',
                ],
            ],
            'grayscale' => [
                'default' => false,
                'type' => 'bool',
                'label' => '灰度效果',
            ],
        ],
    ],

    // --- 表单 (form)：账号认证布局内嵌，全宽背景 + 悬浮登录/注册 ---
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/form/account-login/default.phtml',
        'params' => [
            'background_image' => [
                'type' => 'media_image',
                'label' => '背景图',
                'media_options' => [
                    'default_directory' => 'account/login',
                ],
            ],
            'background_color' => [
                'type' => 'color',
                'label' => '背景主色',
                'default' => '#1f2124',
            ],
            'accent_color' => [
                'type' => 'color',
                'label' => '背景点缀色',
                'default' => '#b84a3c',
            ],
            'overlay_opacity' => [
                'type' => 'select',
                'label' => '背景遮罩',
                'default' => '0.35',
                'options' => [
                    '0' => '无遮罩',
                    '0.2' => '20%',
                    '0.35' => '35%',
                    '0.5' => '50%',
                    '0.65' => '65%',
                ],
            ],
            'promo_eyebrow' => [
                'type' => 'string',
                'label' => '舞台眉题',
                'default' => 'Weline Account',
            ],
            'promo_title' => [
                'type' => 'string',
                'label' => '舞台标题',
                'default' => '欢迎回来',
            ],
            'promo_subtitle' => [
                'type' => 'string',
                'label' => '舞台副文案',
                'default' => '登录后继续浏览优惠与订单',
            ],
            'promo_trust' => [
                'type' => 'string',
                'label' => '舞台信任文案',
                'default' => '安全登录 · 订单与优惠同步',
            ],
            'title' => [
                'type' => 'string',
                'label' => '表单标题',
                'default' => '登录',
            ],
            'subtitle' => [
                'type' => 'string',
                'label' => '表单副标题',
                'default' => '使用您的账户继续购物',
            ],
        ],
    ],
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/form/account-register/default.phtml',
        'params' => [
            'background_image' => [
                'type' => 'media_image',
                'label' => '背景图',
                'media_options' => [
                    'default_directory' => 'account/register',
                ],
            ],
            'background_color' => [
                'type' => 'color',
                'label' => '背景主色',
                'default' => '#1f2124',
            ],
            'accent_color' => [
                'type' => 'color',
                'label' => '背景点缀色',
                'default' => '#b84a3c',
            ],
            'overlay_opacity' => [
                'type' => 'select',
                'label' => '背景遮罩',
                'default' => '0.35',
                'options' => [
                    '0' => '无遮罩',
                    '0.2' => '20%',
                    '0.35' => '35%',
                    '0.5' => '50%',
                    '0.65' => '65%',
                ],
            ],
            'promo_eyebrow' => [
                'type' => 'string',
                'label' => '舞台眉题',
                'default' => 'Weline Account',
            ],
            'promo_title' => [
                'type' => 'string',
                'label' => '舞台标题',
                'default' => '加入我们',
            ],
            'promo_subtitle' => [
                'type' => 'string',
                'label' => '舞台副文案',
                'default' => '创建账户，同步订单与优惠',
            ],
            'promo_trust' => [
                'type' => 'string',
                'label' => '舞台信任文案',
                'default' => '安全注册 · 隐私受保护',
            ],
            'title' => [
                'type' => 'string',
                'label' => '表单标题',
                'default' => '创建账户',
            ],
            'subtitle' => [
                'type' => 'string',
                'label' => '表单副标题',
                'default' => '使用邮箱注册您的账户',
            ],
        ],
    ],
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/form/account-challenge/default.phtml',
        'params' => [
            'background_image' => [
                'type' => 'media_image',
                'label' => '背景图',
                'media_options' => [
                    'default_directory' => 'account/challenge',
                ],
            ],
            'background_color' => [
                'type' => 'color',
                'label' => '背景主色',
                'default' => '#1f2124',
            ],
            'accent_color' => [
                'type' => 'color',
                'label' => '背景点缀色',
                'default' => '#b84a3c',
            ],
            'overlay_opacity' => [
                'type' => 'select',
                'label' => '背景遮罩',
                'default' => '0.35',
                'options' => [
                    '0' => '无遮罩',
                    '0.2' => '20%',
                    '0.35' => '35%',
                    '0.5' => '50%',
                    '0.65' => '65%',
                ],
            ],
            'promo_eyebrow' => [
                'type' => 'string',
                'label' => '舞台眉题',
                'default' => 'Weline Account',
            ],
            'promo_title' => [
                'type' => 'string',
                'label' => '舞台标题',
                'default' => '安全验证',
            ],
            'promo_subtitle' => [
                'type' => 'string',
                'label' => '舞台副文案',
                'default' => '完成两步验证后继续购物与订单',
            ],
            'promo_trust' => [
                'type' => 'string',
                'label' => '舞台信任文案',
                'default' => '验证码仅本次登录有效 · 隐私受保护',
            ],
            'title' => [
                'type' => 'string',
                'label' => '表单标题',
                'default' => '两步验证',
            ],
            'subtitle' => [
                'type' => 'string',
                'label' => '表单副标题',
                'default' => '请输入身份验证器应用生成的验证码或备用恢复码',
            ],
        ],
    ],

    // --- 页脚与订阅 (footer / newsletter / social) ---
    'Weline_Theme::theme/frontend/widgets/footer/footer-links/default.phtml',
    [
        'template' => 'Weline_Theme::theme/frontend/widgets/footer/footer-faq-link/default.phtml',
        'name' => '页脚 FAQ 链接',
        'description' => '页脚帮助扩展槽：跳转 Theme /faq FAQ 布局；默认注入 footer-help-links。',
        'type' => 'footer',
        'code' => 'footer-faq-link',
        'area' => 'frontend',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-help-links',
        'supports' => [
            'footer-faq-link',
            'layout-footer-help-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-help-links',
            'area' => 'footer',
            'sort_order' => 40,
            'required' => true,
            'reason' => '全局 chrome 载体默认展示 Theme /faq 入口；非首页继承合并',
            'config' => [
                'label' => 'FAQ/常见问题',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => 'FAQ/常见问题',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    // footer-newsletter / newsletter-popup：已迁至 Weline_Newsletter（D9/D10 同发布，防双注册）
    'Weline_Theme::theme/frontend/widgets/social/footer-social/default.phtml',
    'Weline_Theme::theme/frontend/widgets/footer/footer-payment/default.phtml',
    'Weline_Theme::theme/frontend/widgets/footer/footer-copyright/default.phtml',
    'Weline_Theme::theme/frontend/widgets/social/social-share/default.phtml',

    // --- 评价 / 信任 / FAQ (testimonial / content / faq) ---
    'Weline_Theme::theme/frontend/widgets/testimonial/testimonials/default.phtml',
    'Weline_Theme::theme/frontend/widgets/content/trust-badges/default.phtml',
    'Weline_Theme::theme/frontend/widgets/faq/faq-accordion/default.phtml',

    // --- 面包屑 / 搜索 / 分页 ---
    'Weline_Theme::theme/frontend/widgets/breadcrumb/breadcrumb/default.phtml',
    'Weline_Theme::theme/frontend/widgets/search/search-bar/default.phtml',
    'Weline_Theme::theme/frontend/widgets/pagination/pagination/default.phtml',
];
