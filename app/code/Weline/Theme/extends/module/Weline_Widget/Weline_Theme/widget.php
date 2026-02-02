<?php

declare(strict_types=1);

/**
 * 部件规约文件：Weline_Theme 模块的部件定义
 * 
 * ============================
 * 支持两种定义格式：
 * ============================
 * 
 * 【格式1】简化格式（推荐）- 只需模板路径，元数据从模板注释自动解析
 * 
 *   return [
 *       // 纯字符串：模板路径
 *       'Weline_Theme::theme/frontend/widgets/search/header-search/default.phtml',
 *       
 *       // 数组：模板路径 + 可选覆盖
 *       [
 *           'template'  => 'Weline_Theme::theme/frontend/widgets/banner/hero-slider/default.phtml',
 *           'exclusive' => true,  // 覆盖模板中的定义
 *       ],
 *   ];
 * 
 *   模板中使用 @widget.* 注释定义元数据：
 *   @widget.code {header-search}
 *   @widget.name {Header 搜索框}
 *   @widget.type {search}
 *   @widget.page_layouts {["*"]}
 *   @param placeholder {default="搜索...",type="string",label="占位符"}
 * 
 * 【格式2】完整格式（兼容旧版）- 在 widget.php 中定义全部配置
 * 
 *   return [
 *       [
 *           'name'        => 'Header 搜索框',
 *           'type'        => 'search',
 *           'code'        => 'header-search',
 *           'template'    => 'Weline_Theme::...',
 *           'page_layouts' => ['*'],
 *           'params'      => [...],
 *       ],
 *   ];
 * 
 * ============================
 * 字段说明：
 * ============================
 * - page_layouts: 适用的布局目录名（layouts/homepage, layouts/category 等）
 * - position: 部件允许放置的位置（header/content/sidebar/footer）
 * - compatible: 部件之间的兼容性
 * - exclusive: 独占部件（同区域只能有一个）
 * - is_container: 容器型部件
 * - slots: 容器内部的插槽定义
 * 
 * 路径规范：
 * - 文件位置：extends/module/Weline_Widget/{ModuleName}/widget.php
 * - 模板路径：Weline_Theme::theme/frontend/widgets/{type}/{code}/default.phtml
 */
return [
    // ==================== 容器型部件（独占） ====================
    
    // Header 容器部件 - 独占整个头部区域
    [
        'name'        => 'Header 容器',
        'description' => '页面头部容器，包含 Logo、搜索框、导航、用户区域等插槽。独占头部区域。',
        'type'        => 'container',
        'code'        => 'header-container',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => false,
        'is_container' => true,  // 标识为容器型部件
        'exclusive'   => true,   // 独占部件（同区域只能有一个）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/container/header/default.phtml',
        // 适用的页面类型（通用 = 所有页面都可用）
        'page_layouts' => ['*'],  // * 表示所有页面类型
        // 定义容器内部的插槽
        'slots'       => [
            'logo' => [
                'name'     => 'Logo 插槽',
                'position' => 'left',
                'accept'   => ['logo'],  // 接受的部件类型
                'max'      => 1,         // 最多放置数量
            ],
            'search' => [
                'name'     => '搜索框插槽',
                'position' => 'center',
                'accept'   => ['header-search'],
                'max'      => 1,
            ],
            'navigation' => [
                'name'     => '导航菜单插槽',
                'position' => 'center-bottom',
                'accept'   => ['main-nav', 'category-menu'],
                'max'      => 1,
            ],
            'user-area' => [
                'name'     => '用户区域插槽',
                'position' => 'right',
                'accept'   => ['account', 'mini-cart-icon', 'wishlist-icon', 'language-switcher', 'currency-switcher'],
                'max'      => 5,
            ],
        ],
        'params'      => [
            'sticky' => [
                'type'    => 'bool',
                'label'   => '固定头部',
                'default' => true,
            ],
            'bg_color' => [
                'type'    => 'color',
                'label'   => '背景颜色',
                'default' => '#ffffff',
            ],
            'shadow' => [
                'type'    => 'bool',
                'label'   => '显示阴影',
                'default' => true,
            ],
            'layout_style' => [
                'type'    => 'select',
                'label'   => '布局样式',
                'default' => 'default',
                'options' => [
                    'default' => '默认（Logo左 - 搜索中 - 用户右）',
                    'centered' => '居中（Logo中央）',
                    'minimal'  => '极简（仅Logo和菜单）',
                ],
            ],
        ],
    ],

    // Footer 容器部件 - 独占整个底部区域
    [
        'name'        => 'Footer 容器',
        'description' => '页面底部容器，包含链接、订阅、社交、版权等插槽。独占底部区域。',
        'type'        => 'container',
        'code'        => 'footer-container',
        'area'        => 'frontend',
        'position'    => ['footer'],
        'compatible'  => false,
        'is_container' => true,
        'exclusive'   => true,   // 独占部件
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/container/footer/default.phtml',
        'page_layouts' => ['*'],  // 所有页面类型
        'slots'       => [
            'links' => [
                'name'     => '链接区插槽',
                'position' => 'top',
                'accept'   => ['footer-links'],
                'max'      => 4,
            ],
            'newsletter' => [
                'name'     => '订阅区插槽',
                'position' => 'top-right',
                'accept'   => ['footer-newsletter'],
                'max'      => 1,
            ],
            'social' => [
                'name'     => '社交媒体插槽',
                'position' => 'bottom-left',
                'accept'   => ['footer-social'],
                'max'      => 1,
            ],
            'payment' => [
                'name'     => '支付方式插槽',
                'position' => 'bottom-center',
                'accept'   => ['footer-payment'],
                'max'      => 1,
            ],
            'copyright' => [
                'name'     => '版权信息插槽',
                'position' => 'bottom-right',
                'accept'   => ['footer-copyright'],
                'max'      => 1,
            ],
        ],
        'params'      => [
            'bg_color' => [
                'type'    => 'color',
                'label'   => '背景颜色',
                'default' => '#1a1a2e',
            ],
            'text_color' => [
                'type'    => 'color',
                'label'   => '文字颜色',
                'default' => '#ffffff',
            ],
            'layout_style' => [
                'type'    => 'select',
                'label'   => '布局样式',
                'default' => 'default',
                'options' => [
                    'default'  => '默认（四栏布局）',
                    'centered' => '居中布局',
                    'minimal'  => '极简布局',
                ],
            ],
        ],
    ],

    // Content 容器部件 - 独占主内容区域
    [
        'name'        => 'Content 容器',
        'description' => '页面主内容区域容器，可包含产品列表、轮播、推荐等部件。独占内容区域。',
        'type'        => 'container',
        'code'        => 'content-container',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'is_container' => true,
        'exclusive'   => true,   // 独占部件
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/container/content/default.phtml',
        'page_layouts' => ['*'],  // 所有页面类型
        'slots'       => [
            'hero' => [
                'name'     => 'Hero 区域',
                'position' => 'top',
                'accept'   => ['hero-slider', 'promo-banner'],
                'max'      => 1,
            ],
            'featured' => [
                'name'     => '推荐区域',
                'position' => 'top-middle',
                'accept'   => ['featured-products', 'category-grid', 'deals-of-day'],
                'max'      => 2,
            ],
            'main' => [
                'name'     => '主内容区',
                'position' => 'middle',
                'accept'   => ['*'],  // 接受所有 content 类型部件
                'max'      => 10,
            ],
            'sidebar-left' => [
                'name'     => '左侧栏',
                'position' => 'left',
                'accept'   => ['category-filters', 'category-list', 'sidebar-menu', 'sidebar-ads'],
                'max'      => 5,
            ],
            'sidebar-right' => [
                'name'     => '右侧栏',
                'position' => 'right',
                'accept'   => ['mini-cart', 'recently-viewed', 'sidebar-newsletter', 'sidebar-social', 'tags-cloud'],
                'max'      => 5,
            ],
            'bottom' => [
                'name'     => '底部区域',
                'position' => 'bottom',
                'accept'   => ['testimonials', 'brand-logos', 'faq-accordion', 'trust-badges'],
                'max'      => 3,
            ],
        ],
        'params'      => [
            'layout' => [
                'type'    => 'select',
                'label'   => '布局方式',
                'default' => 'full-width',
                'options' => [
                    'full-width'    => '全宽布局',
                    'with-left'     => '带左侧栏',
                    'with-right'    => '带右侧栏',
                    'with-both'     => '双侧栏',
                ],
            ],
            'sidebar_width' => [
                'type'    => 'select',
                'label'   => '侧栏宽度',
                'default' => '25',
                'options' => [
                    '20' => '20%',
                    '25' => '25%',
                    '30' => '30%',
                ],
            ],
            'container_width' => [
                'type'    => 'select',
                'label'   => '容器宽度',
                'default' => 'container',
                'options' => [
                    'container'       => '标准容器',
                    'container-fluid' => '全宽容器',
                    'container-lg'    => '大容器',
                ],
            ],
        ],
    ],

    // ==================== Header 子部件（通用 - 所有页面） ====================
    
    // Header Logo 部件
    [
        'name'        => 'Header Logo',
        'description' => '网站Logo部件，支持图片Logo和文字Logo',
        'type'        => 'header',
        'code'        => 'logo',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => false,
        'exclusive'   => true,   // 独占 logo 插槽
        'slot'        => 'logo',  // 指定放入容器的 logo 插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/header/logo/default.phtml',
        'params'      => [
            'logo_image' => [
                'type'        => 'image',
                'label'       => 'Logo图片',
                'default'     => '',
                'required'    => false,
                'description' => '上传Logo图片',
            ],
            'logo_text' => [
                'type'        => 'string',
                'label'       => 'Logo文字',
                'default'     => 'WeShop',
                'required'    => false,
                'description' => '当没有Logo图片时显示的文字',
            ],
            'logo_link' => [
                'type'        => 'url',
                'label'       => 'Logo链接',
                'default'     => '/',
                'required'    => false,
                'description' => '点击Logo跳转的链接',
            ],
            'logo_height' => [
                'type'        => 'number',
                'label'       => 'Logo高度(px)',
                'default'     => 40,
                'min'         => 20,
                'max'         => 120,
                'required'    => false,
            ],
        ],
    ],

    // Header 导航菜单部件
    [
        'name'        => 'Header 导航菜单',
        'description' => '主导航菜单，支持多级下拉菜单',
        'type'        => 'navigation',
        'code'        => 'main-nav',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => false,
        'exclusive'   => true,   // 独占导航插槽
        'slot'        => 'navigation',  // 放入容器的导航插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/navigation/main-nav/default.phtml',
        'params'      => [
            'menu_style' => [
                'type'        => 'select',
                'label'       => '菜单样式',
                'default'     => 'horizontal',
                'options'     => [
                    'horizontal' => '水平菜单',
                    'vertical'   => '垂直菜单',
                    'mega'       => '超级菜单',
                ],
            ],
            'show_icons' => [
                'type'        => 'bool',
                'label'       => '显示图标',
                'default'     => false,
            ],
            'max_depth' => [
                'type'        => 'number',
                'label'       => '最大层级',
                'default'     => 3,
                'min'         => 1,
                'max'         => 5,
            ],
        ],
    ],

    // Header 搜索框部件
    [
        'name'        => 'Header 搜索框',
        'description' => '搜索框部件，支持热词和自动补全',
        'type'        => 'search',
        'code'        => 'header-search',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => false,
        'exclusive'   => true,   // 独占搜索插槽
        'slot'        => 'search',  // 放入容器的搜索插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/search/header-search/default.phtml',
        'params'      => [
            'placeholder' => [
                'type'        => 'string',
                'label'       => '占位符文字',
                'default'     => '搜索商品...',
            ],
            'show_hot_words' => [
                'type'        => 'bool',
                'label'       => '显示热搜词',
                'default'     => true,
            ],
            'auto_complete' => [
                'type'        => 'bool',
                'label'       => '启用自动补全',
                'default'     => true,
            ],
            'search_type' => [
                'type'        => 'select',
                'label'       => '搜索范围',
                'default'     => 'all',
                'options'     => [
                    'all'      => '全站搜索',
                    'product'  => '仅搜商品',
                    'category' => '仅搜分类',
                ],
            ],
        ],
    ],

    // Header 购物车图标部件
    [
        'name'        => 'Header 购物车',
        'description' => '购物车图标，显示购物车商品数量和小计',
        'type'        => 'header',
        'code'        => 'mini-cart-icon',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => false,
        'slot'        => 'user-area',  // 放入容器的用户区域插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/header/mini-cart-icon/default.phtml',
        'params'      => [
            'show_quantity' => [
                'type'        => 'bool',
                'label'       => '显示数量',
                'default'     => true,
            ],
            'show_subtotal' => [
                'type'        => 'bool',
                'label'       => '显示小计',
                'default'     => true,
            ],
            'icon_style' => [
                'type'        => 'select',
                'label'       => '图标样式',
                'default'     => 'cart',
                'options'     => [
                    'cart'   => '购物车',
                    'bag'    => '购物袋',
                    'basket' => '购物篮',
                ],
            ],
        ],
    ],

    // Header 用户账户部件
    [
        'name'        => 'Header 用户账户',
        'description' => '用户账户入口，登录/注册/账户中心',
        'type'        => 'header',
        'code'        => 'account',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => false,
        'slot'        => 'user-area',
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/header/account/default.phtml',
        'params'      => [
            'show_avatar' => [
                'type'        => 'bool',
                'label'       => '显示头像',
                'default'     => true,
            ],
            'show_name' => [
                'type'        => 'bool',
                'label'       => '显示用户名',
                'default'     => false,
            ],
            'dropdown_items' => [
                'type'        => 'array',
                'label'       => '下拉菜单项',
                'default'     => [],
                'description' => '登录后显示的下拉菜单项',
            ],
        ],
    ],

    // Header 语言切换部件
    [
        'name'        => 'Header 语言切换',
        'description' => '多语言切换下拉菜单',
        'type'        => 'header',
        'code'        => 'language-switcher',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => true,
        'slot'        => 'user-area',
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/header/language-switcher/default.phtml',
        'params'      => [
            'show_flag' => [
                'type'        => 'bool',
                'label'       => '显示国旗',
                'default'     => true,
            ],
            'show_name' => [
                'type'        => 'bool',
                'label'       => '显示语言名',
                'default'     => true,
            ],
            'display_style' => [
                'type'        => 'select',
                'label'       => '显示样式',
                'default'     => 'dropdown',
                'options'     => [
                    'dropdown' => '下拉菜单',
                    'inline'   => '水平排列',
                ],
            ],
        ],
    ],

    // Header 货币切换部件
    [
        'name'        => 'Header 货币切换',
        'description' => '多货币切换下拉菜单',
        'type'        => 'header',
        'code'        => 'currency-switcher',
        'area'        => 'frontend',
        'position'    => ['header'],
        'compatible'  => true,
        'slot'        => 'user-area',
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/header/currency-switcher/default.phtml',
        'params'      => [
            'show_symbol' => [
                'type'        => 'bool',
                'label'       => '显示货币符号',
                'default'     => true,
            ],
            'show_code' => [
                'type'        => 'bool',
                'label'       => '显示货币代码',
                'default'     => true,
            ],
        ],
    ],

    // ==================== Banner 部件 ====================

    // Hero 大图轮播
    [
        'name'        => 'Hero 轮播图',
        'description' => '首页大图轮播Banner，支持多张图片轮播展示',
        'type'        => 'banner',
        'code'        => 'hero-slider',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page'],  // 首页和CMS页面布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/banner/hero-slider/default.phtml',
        'params'      => [
            'slides' => [
                'type'        => 'array',
                'label'       => '轮播图片',
                'default'     => [],
                'description' => '图片数组，每项包含image、title、subtitle、link、button_text',
            ],
            'autoplay' => [
                'type'        => 'bool',
                'label'       => '自动播放',
                'default'     => true,
            ],
            'autoplay_speed' => [
                'type'        => 'number',
                'label'       => '自动播放间隔(ms)',
                'default'     => 5000,
                'min'         => 1000,
                'max'         => 20000,
            ],
            'show_dots' => [
                'type'        => 'bool',
                'label'       => '显示指示点',
                'default'     => true,
            ],
            'show_arrows' => [
                'type'        => 'bool',
                'label'       => '显示箭头',
                'default'     => true,
            ],
            'effect' => [
                'type'        => 'select',
                'label'       => '切换效果',
                'default'     => 'slide',
                'options'     => [
                    'slide' => '滑动',
                    'fade'  => '淡入淡出',
                ],
            ],
            'height' => [
                'type'        => 'string',
                'label'       => '轮播高度',
                'default'     => '500px',
                'description' => '支持px、vh、%等单位',
            ],
        ],
    ],

    // 促销横幅
    [
        'name'        => '促销横幅',
        'description' => '促销活动横幅，可设置背景图、文字和链接',
        'type'        => 'banner',
        'code'        => 'promo-banner',
        'area'        => 'frontend',
        'position'    => ['header', 'content'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/banner/promo-banner/default.phtml',
        'params'      => [
            'background_image' => [
                'type'        => 'image',
                'label'       => '背景图片',
                'default'     => '',
            ],
            'background_color' => [
                'type'        => 'color',
                'label'       => '背景颜色',
                'default'     => '#f8d7da',
            ],
            'text' => [
                'type'        => 'string',
                'label'       => '促销文字',
                'default'     => '限时特惠！全场满300减50',
            ],
            'text_color' => [
                'type'        => 'color',
                'label'       => '文字颜色',
                'default'     => '#721c24',
            ],
            'link' => [
                'type'        => 'url',
                'label'       => '链接地址',
                'default'     => '',
            ],
            'closeable' => [
                'type'        => 'bool',
                'label'       => '允许关闭',
                'default'     => true,
            ],
            'countdown_end' => [
                'type'        => 'string',
                'label'       => '倒计时结束时间',
                'default'     => '',
                'description' => '格式：YYYY-MM-DD HH:mm:ss，为空则不显示倒计时',
            ],
        ],
    ],

    // 广告横幅
    [
        'name'        => '广告横幅',
        'description' => '图片广告横幅，支持多种尺寸',
        'type'        => 'banner',
        'code'        => 'ad-banner',
        'area'        => 'frontend',
        'position'    => ['content', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/banner/ad-banner/default.phtml',
        'params'      => [
            'image' => [
                'type'        => 'image',
                'label'       => '广告图片',
                'default'     => '',
            ],
            'link' => [
                'type'        => 'url',
                'label'       => '链接地址',
                'default'     => '',
            ],
            'alt_text' => [
                'type'        => 'string',
                'label'       => '替代文字',
                'default'     => '',
            ],
            'open_new_tab' => [
                'type'        => 'bool',
                'label'       => '新窗口打开',
                'default'     => false,
            ],
            'border_radius' => [
                'type'        => 'string',
                'label'       => '圆角大小',
                'default'     => '8px',
            ],
        ],
    ],

    // ==================== Product 部件 ====================

    // 推荐产品
    [
        'name'        => '推荐产品',
        'description' => '精选推荐产品展示，可自定义选择产品或按规则自动获取',
        'type'        => 'product',
        'code'        => 'featured-products',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page', 'category'],  // 首页、CMS页和分类页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/featured-products/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '推荐产品',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '为您精选的优质商品',
            ],
            'product_ids' => [
                'type'        => 'string',
                'label'       => '产品ID',
                'default'     => '',
                'description' => '多个ID用逗号分隔，留空则自动获取',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 8,
                'min'         => 1,
                'max'         => 24,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                ],
            ],
            'show_price' => [
                'type'        => 'bool',
                'label'       => '显示价格',
                'default'     => true,
            ],
            'show_rating' => [
                'type'        => 'bool',
                'label'       => '显示评分',
                'default'     => true,
            ],
            'show_add_to_cart' => [
                'type'        => 'bool',
                'label'       => '显示加购按钮',
                'default'     => true,
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'grid',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
        ],
    ],

    // 新品到达
    [
        'name'        => '新品到达',
        'description' => '最新上架的产品展示',
        'type'        => 'product',
        'code'        => 'new-arrivals',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page', 'category'],  // 首页、CMS页和分类页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/new-arrivals/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '新品上架',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '发现最新商品',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 8,
                'min'         => 1,
                'max'         => 24,
            ],
            'days' => [
                'type'        => 'number',
                'label'       => '新品天数',
                'default'     => 30,
                'min'         => 1,
                'max'         => 365,
                'description' => '多少天内上架的算新品',
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                ],
            ],
            'show_new_badge' => [
                'type'        => 'bool',
                'label'       => '显示NEW标签',
                'default'     => true,
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'grid',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
        ],
    ],

    // 畅销产品
    [
        'name'        => '畅销产品',
        'description' => '按销量排序的热销产品展示',
        'type'        => 'product',
        'code'        => 'bestsellers',
        'area'        => 'frontend',
        'position'    => ['content', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['homepage', 'cms_page', 'category'],  // 首页、CMS页和分类页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/bestsellers/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '热销产品',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '最受欢迎的商品',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 8,
                'min'         => 1,
                'max'         => 24,
            ],
            'period' => [
                'type'        => 'select',
                'label'       => '统计周期',
                'default'     => 'month',
                'options'     => [
                    'week'    => '本周',
                    'month'   => '本月',
                    'quarter' => '本季度',
                    'year'    => '本年',
                    'all'     => '全部时间',
                ],
            ],
            'show_sales_count' => [
                'type'        => 'bool',
                'label'       => '显示销量',
                'default'     => false,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'grid',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                    'list'     => '列表布局',
                ],
            ],
        ],
    ],

    // 今日特价
    [
        'name'        => '今日特价',
        'description' => '限时特价产品展示，支持倒计时',
        'type'        => 'product',
        'code'        => 'deals-of-day',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page'],  // 首页和CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/deals-of-day/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '今日特价',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '限时抢购，错过不再',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 4,
                'min'         => 1,
                'max'         => 12,
            ],
            'show_countdown' => [
                'type'        => 'bool',
                'label'       => '显示倒计时',
                'default'     => true,
            ],
            'countdown_end' => [
                'type'        => 'string',
                'label'       => '倒计时结束时间',
                'default'     => '',
                'description' => '格式：YYYY-MM-DD HH:mm:ss',
            ],
            'show_discount_percent' => [
                'type'        => 'bool',
                'label'       => '显示折扣比例',
                'default'     => true,
            ],
            'show_stock_bar' => [
                'type'        => 'bool',
                'label'       => '显示库存进度条',
                'default'     => true,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                ],
            ],
        ],
    ],

    // 相关产品
    [
        'name'        => '相关产品',
        'description' => '基于当前产品推荐相关产品',
        'type'        => 'product',
        'code'        => 'related-products',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['product'],  // 产品详情页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/related-products/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '相关产品',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 4,
                'min'         => 1,
                'max'         => 12,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'carousel',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
        ],
    ],

    // 最近浏览
    [
        'name'        => '最近浏览',
        'description' => '用户最近浏览过的产品',
        'type'        => 'product',
        'code'        => 'recently-viewed',
        'area'        => 'frontend',
        'position'    => ['content', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/recently-viewed/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '最近浏览',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 6,
                'min'         => 1,
                'max'         => 12,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '6',
                'options'     => [
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'carousel',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
            'show_price' => [
                'type'        => 'bool',
                'label'       => '显示价格',
                'default'     => true,
            ],
        ],
    ],

    // 猜你喜欢
    [
        'name'        => '猜你喜欢',
        'description' => '基于用户行为的个性化推荐',
        'type'        => 'product',
        'code'        => 'you-may-like',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/you-may-like/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '猜你喜欢',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '根据您的浏览记录推荐',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 8,
                'min'         => 1,
                'max'         => 24,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'grid',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
        ],
    ],

    // 交叉销售
    [
        'name'        => '交叉销售',
        'description' => '经常一起购买的产品推荐',
        'type'        => 'product',
        'code'        => 'cross-sell',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['product', 'cart'],  // 产品页和购物车布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/cross-sell/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '经常一起购买',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 4,
                'min'         => 1,
                'max'         => 8,
            ],
            'show_add_all_button' => [
                'type'        => 'bool',
                'label'       => '显示全部加购按钮',
                'default'     => true,
            ],
            'show_total_price' => [
                'type'        => 'bool',
                'label'       => '显示总价',
                'default'     => true,
            ],
        ],
    ],

    // 向上销售
    [
        'name'        => '向上销售',
        'description' => '推荐更高价值的替代产品',
        'type'        => 'product',
        'code'        => 'up-sell',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['product'],  // 产品详情页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/product/up-sell/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '您可能还喜欢',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 4,
                'min'         => 1,
                'max'         => 8,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'carousel',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
        ],
    ],

    // 产品轮播
    [
        'name'        => '产品轮播',
        'description' => '通用产品轮播展示组件',
        'type'        => 'carousel',
        'code'        => 'product-carousel',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page', 'category'],  // 首页、CMS页和分类页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/carousel/product-carousel/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '',
            ],
            'product_source' => [
                'type'        => 'select',
                'label'       => '产品来源',
                'default'     => 'featured',
                'options'     => [
                    'featured'   => '推荐产品',
                    'new'        => '新品',
                    'bestseller' => '畅销',
                    'sale'       => '促销',
                    'custom'     => '自定义ID',
                ],
            ],
            'product_ids' => [
                'type'        => 'string',
                'label'       => '产品ID',
                'default'     => '',
                'description' => '仅当来源选择"自定义ID"时有效',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 10,
                'min'         => 1,
                'max'         => 30,
            ],
            'slides_per_view' => [
                'type'        => 'number',
                'label'       => '可视数量',
                'default'     => 4,
                'min'         => 1,
                'max'         => 8,
            ],
            'autoplay' => [
                'type'        => 'bool',
                'label'       => '自动播放',
                'default'     => false,
            ],
            'loop' => [
                'type'        => 'bool',
                'label'       => '循环播放',
                'default'     => true,
            ],
        ],
    ],

    // ==================== Category 部件 ====================

    // 分类列表
    [
        'name'        => '分类列表',
        'description' => '以列表形式展示商品分类',
        'type'        => 'category',
        'code'        => 'category-list',
        'area'        => 'frontend',
        'position'    => ['content', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/category/category-list/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '商品分类',
            ],
            'parent_id' => [
                'type'        => 'number',
                'label'       => '父分类ID',
                'default'     => 0,
                'description' => '0表示顶级分类',
            ],
            'max_depth' => [
                'type'        => 'number',
                'label'       => '最大层级',
                'default'     => 2,
                'min'         => 1,
                'max'         => 5,
            ],
            'show_count' => [
                'type'        => 'bool',
                'label'       => '显示产品数量',
                'default'     => true,
            ],
            'show_image' => [
                'type'        => 'bool',
                'label'       => '显示分类图片',
                'default'     => false,
            ],
            'collapsed' => [
                'type'        => 'bool',
                'label'       => '默认折叠',
                'default'     => false,
            ],
        ],
    ],

    // 分类网格
    [
        'name'        => '分类网格',
        'description' => '以网格形式展示商品分类，带图片',
        'type'        => 'category',
        'code'        => 'category-grid',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page'],  // 首页和CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/category/category-grid/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '热门分类',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '浏览我们的商品分类',
            ],
            'category_ids' => [
                'type'        => 'string',
                'label'       => '分类ID',
                'default'     => '',
                'description' => '多个ID用逗号分隔，留空显示所有顶级分类',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 8,
                'min'         => 1,
                'max'         => 24,
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                ],
            ],
            'show_count' => [
                'type'        => 'bool',
                'label'       => '显示产品数量',
                'default'     => true,
            ],
            'image_ratio' => [
                'type'        => 'select',
                'label'       => '图片比例',
                'default'     => 'square',
                'options'     => [
                    'square'    => '1:1 正方形',
                    'landscape' => '16:9 横向',
                    'portrait'  => '3:4 纵向',
                ],
            ],
        ],
    ],

    // 分类菜单
    [
        'name'        => '分类菜单',
        'description' => '分类导航菜单，支持多级展开',
        'type'        => 'navigation',
        'code'        => 'category-menu',
        'area'        => 'frontend',
        'position'    => ['header', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/navigation/category-menu/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '全部分类',
            ],
            'max_depth' => [
                'type'        => 'number',
                'label'       => '最大层级',
                'default'     => 3,
                'min'         => 1,
                'max'         => 5,
            ],
            'show_icons' => [
                'type'        => 'bool',
                'label'       => '显示图标',
                'default'     => true,
            ],
            'show_count' => [
                'type'        => 'bool',
                'label'       => '显示数量',
                'default'     => false,
            ],
            'expand_on_hover' => [
                'type'        => 'bool',
                'label'       => '悬停展开',
                'default'     => true,
            ],
            'style' => [
                'type'        => 'select',
                'label'       => '菜单样式',
                'default'     => 'flyout',
                'options'     => [
                    'flyout'    => '飞出菜单',
                    'accordion' => '手风琴',
                    'mega'      => '超级菜单',
                ],
            ],
        ],
    ],

    // ==================== Sidebar 部件 ====================

    // 分类筛选侧栏（已存在，保留）
    [
        'name'        => '分类筛选侧栏',
        'description' => '分类页左侧属性筛选部件，支持属性分组、数量展示与已选条件展示（亚马逊风格）。',
        'type'        => 'sidebar',
        'code'        => 'category-filters',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['category', 'search'],  // 分类页和搜索页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/category-filters/default.phtml',
        'doc'         => 'sidebar/分类筛选侧栏.md',
        'params'      => [
            'html' => [
                'type'        => 'string',
                'label'       => '自定义筛选HTML',
                'default'     => '',
                'required'    => false,
                'description' => '可选：业务模块渲染好的筛选HTML。如果为空，则使用默认亚马逊风格示例内容。',
            ],
        ],
    ],

    // 侧栏菜单
    [
        'name'        => '侧栏菜单',
        'description' => '侧栏导航菜单，支持多级展开',
        'type'        => 'sidebar',
        'code'        => 'sidebar-menu',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-menu/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '导航菜单',
            ],
            'menu_items' => [
                'type'        => 'array',
                'label'       => '菜单项',
                'default'     => [],
                'description' => '菜单项数组，每项包含label、url、icon、children',
            ],
            'collapsed_by_default' => [
                'type'        => 'bool',
                'label'       => '默认折叠子菜单',
                'default'     => false,
            ],
            'show_icons' => [
                'type'        => 'bool',
                'label'       => '显示图标',
                'default'     => true,
            ],
        ],
    ],

    // 迷你购物车
    [
        'name'        => '迷你购物车',
        'description' => '侧栏迷你购物车，显示购物车内容摘要',
        'type'        => 'sidebar',
        'code'        => 'mini-cart',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/sidebar/mini-cart/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '购物车',
            ],
            'max_items' => [
                'type'        => 'number',
                'label'       => '最大显示数量',
                'default'     => 5,
                'min'         => 1,
                'max'         => 10,
            ],
            'show_subtotal' => [
                'type'        => 'bool',
                'label'       => '显示小计',
                'default'     => true,
            ],
            'show_checkout_button' => [
                'type'        => 'bool',
                'label'       => '显示结账按钮',
                'default'     => true,
            ],
            'show_view_cart_button' => [
                'type'        => 'bool',
                'label'       => '显示查看购物车按钮',
                'default'     => true,
            ],
        ],
    ],

    // 邮件订阅（侧栏）
    [
        'name'        => '邮件订阅（侧栏）',
        'description' => '侧栏邮件订阅表单',
        'type'        => 'sidebar',
        'code'        => 'sidebar-newsletter',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-newsletter/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '订阅我们',
            ],
            'description' => [
                'type'        => 'string',
                'label'       => '描述文字',
                'default'     => '订阅获取最新优惠信息',
            ],
            'button_text' => [
                'type'        => 'string',
                'label'       => '按钮文字',
                'default'     => '订阅',
            ],
            'placeholder' => [
                'type'        => 'string',
                'label'       => '输入框占位符',
                'default'     => '请输入邮箱地址',
            ],
        ],
    ],

    // 侧栏广告
    [
        'name'        => '侧栏广告',
        'description' => '侧栏图片广告位',
        'type'        => 'sidebar',
        'code'        => 'sidebar-ads',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-ads/default.phtml',
        'params'      => [
            'ads' => [
                'type'        => 'array',
                'label'       => '广告列表',
                'default'     => [],
                'description' => '广告数组，每项包含image、link、alt、open_new_tab',
            ],
            'show_label' => [
                'type'        => 'bool',
                'label'       => '显示广告标识',
                'default'     => true,
            ],
        ],
    ],

    // 标签云
    [
        'name'        => '标签云',
        'description' => '热门标签云展示',
        'type'        => 'sidebar',
        'code'        => 'tags-cloud',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/sidebar/tags-cloud/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '热门标签',
            ],
            'limit' => [
                'type'        => 'number',
                'label'       => '显示数量',
                'default'     => 20,
                'min'         => 5,
                'max'         => 50,
            ],
            'order_by' => [
                'type'        => 'select',
                'label'       => '排序方式',
                'default'     => 'count',
                'options'     => [
                    'count'   => '按热度',
                    'name'    => '按名称',
                    'random'  => '随机',
                ],
            ],
            'show_count' => [
                'type'        => 'bool',
                'label'       => '显示数量',
                'default'     => false,
            ],
        ],
    ],

    // 社交链接（侧栏）
    [
        'name'        => '社交链接（侧栏）',
        'description' => '侧栏社交媒体链接',
        'type'        => 'sidebar',
        'code'        => 'sidebar-social',
        'area'        => 'frontend',
        'position'    => ['sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-social/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '关注我们',
                'translatable' => true,  // 支持多语言
            ],
            'icon_style' => [
                'type'        => 'select',
                'label'       => '图标样式',
                'default'     => 'colored',
                'options'     => [
                    'colored' => '彩色',
                    'mono'    => '单色',
                    'outline' => '线框',
                ],
            ],
            'gap' => [
                'type'        => 'string',
                'label'       => '图标间距',
                'default'     => '10px',
                'description' => '支持CSS单位（如10px、1rem）',
            ],
            'wechat_qr' => [
                'type'        => 'image',
                'label'       => '微信二维码',
                'default'     => '',
            ],
            'custom_links' => [
                'type'        => 'string',
                'label'       => '自定义社交链接',
                'default'     => '',
                'description' => 'JSON格式，例：[{"platform":"facebook","url":"https://..."}]',
            ],
            'facebook' => [
                'type'        => 'url',
                'label'       => 'Facebook链接',
                'default'     => '',
            ],
            'twitter' => [
                'type'        => 'url',
                'label'       => 'Twitter/X链接',
                'default'     => '',
            ],
            'instagram' => [
                'type'        => 'url',
                'label'       => 'Instagram链接',
                'default'     => '',
            ],
            'youtube' => [
                'type'        => 'url',
                'label'       => 'YouTube链接',
                'default'     => '',
            ],
            'linkedin' => [
                'type'        => 'url',
                'label'       => 'LinkedIn链接',
                'default'     => '',
            ],
            'pinterest' => [
                'type'        => 'url',
                'label'       => 'Pinterest链接',
                'default'     => '',
            ],
            'tiktok' => [
                'type'        => 'url',
                'label'       => 'TikTok链接',
                'default'     => '',
            ],
            'weibo' => [
                'type'        => 'url',
                'label'       => '微博链接',
                'default'     => '',
            ],
            'wechat' => [
                'type'        => 'url',
                'label'       => '微信链接',
                'default'     => '',
            ],
            'github' => [
                'type'        => 'url',
                'label'       => 'GitHub链接',
                'default'     => '',
            ],
            'telegram' => [
                'type'        => 'url',
                'label'       => 'Telegram链接',
                'default'     => '',
            ],
            'whatsapp' => [
                'type'        => 'url',
                'label'       => 'WhatsApp链接',
                'default'     => '',
            ],
            'discord' => [
                'type'        => 'url',
                'label'       => 'Discord链接',
                'default'     => '',
            ],
            'reddit' => [
                'type'        => 'url',
                'label'       => 'Reddit链接',
                'default'     => '',
            ],
            'snapchat' => [
                'type'        => 'url',
                'label'       => 'Snapchat链接',
                'default'     => '',
            ],
        ],
    ],

    // ==================== Content 部件 ====================

    // 文本块
    [
        'name'        => '文本块',
        'description' => '富文本内容块，支持HTML',
        'type'        => 'content',
        'code'        => 'text-block',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/content/text-block/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '',
            ],
            'content' => [
                'type'        => 'string',
                'label'       => '内容',
                'default'     => '',
                'description' => '支持HTML内容',
            ],
            'alignment' => [
                'type'        => 'select',
                'label'       => '对齐方式',
                'default'     => 'left',
                'options'     => [
                    'left'   => '左对齐',
                    'center' => '居中',
                    'right'  => '右对齐',
                ],
            ],
            'background_color' => [
                'type'        => 'color',
                'label'       => '背景颜色',
                'default'     => 'transparent',
            ],
            'padding' => [
                'type'        => 'string',
                'label'       => '内边距',
                'default'     => '20px',
            ],
        ],
    ],

    // 图文组合
    [
        'name'        => '图文组合',
        'description' => '图片和文字组合展示',
        'type'        => 'content',
        'code'        => 'image-text',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/content/image-text/default.phtml',
        'params'      => [
            'image' => [
                'type'        => 'image',
                'label'       => '图片',
                'default'     => '',
            ],
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '',
            ],
            'content' => [
                'type'        => 'string',
                'label'       => '内容',
                'default'     => '',
            ],
            'button_text' => [
                'type'        => 'string',
                'label'       => '按钮文字',
                'default'     => '',
            ],
            'button_link' => [
                'type'        => 'url',
                'label'       => '按钮链接',
                'default'     => '',
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局',
                'default'     => 'image-left',
                'options'     => [
                    'image-left'  => '图左文右',
                    'image-right' => '图右文左',
                    'image-top'   => '图上文下',
                    'image-bg'    => '图片背景',
                ],
            ],
            'image_width' => [
                'type'        => 'select',
                'label'       => '图片宽度',
                'default'     => '50',
                'options'     => [
                    '33' => '1/3',
                    '50' => '1/2',
                    '66' => '2/3',
                ],
            ],
        ],
    ],

    // 视频播放器
    [
        'name'        => '视频播放器',
        'description' => '嵌入视频播放，支持YouTube、Vimeo、本地视频',
        'type'        => 'video',
        'code'        => 'video-player',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/video/video-player/default.phtml',
        'params'      => [
            'video_type' => [
                'type'        => 'select',
                'label'       => '视频类型',
                'default'     => 'youtube',
                'options'     => [
                    'youtube' => 'YouTube',
                    'vimeo'   => 'Vimeo',
                    'local'   => '本地视频',
                    'embed'   => '嵌入代码',
                ],
            ],
            'video_url' => [
                'type'        => 'url',
                'label'       => '视频地址/ID',
                'default'     => '',
            ],
            'embed_code' => [
                'type'        => 'string',
                'label'       => '嵌入代码',
                'default'     => '',
                'description' => '仅当视频类型为"嵌入代码"时有效',
            ],
            'poster' => [
                'type'        => 'image',
                'label'       => '封面图',
                'default'     => '',
            ],
            'autoplay' => [
                'type'        => 'bool',
                'label'       => '自动播放',
                'default'     => false,
            ],
            'muted' => [
                'type'        => 'bool',
                'label'       => '静音',
                'default'     => false,
            ],
            'loop' => [
                'type'        => 'bool',
                'label'       => '循环播放',
                'default'     => false,
            ],
            'aspect_ratio' => [
                'type'        => 'select',
                'label'       => '视频比例',
                'default'     => '16:9',
                'options'     => [
                    '16:9' => '16:9',
                    '4:3'  => '4:3',
                    '1:1'  => '1:1',
                    '9:16' => '9:16 竖屏',
                ],
            ],
        ],
    ],

    // 倒计时
    [
        'name'        => '倒计时',
        'description' => '活动倒计时组件',
        'type'        => 'content',
        'code'        => 'countdown',
        'area'        => 'frontend',
        'position'    => ['content', 'header'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/content/countdown/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '限时活动',
            ],
            'end_date' => [
                'type'        => 'string',
                'label'       => '结束时间',
                'default'     => '',
                'description' => '格式：YYYY-MM-DD HH:mm:ss',
            ],
            'expired_text' => [
                'type'        => 'string',
                'label'       => '过期提示',
                'default'     => '活动已结束',
            ],
            'show_days' => [
                'type'        => 'bool',
                'label'       => '显示天数',
                'default'     => true,
            ],
            'show_hours' => [
                'type'        => 'bool',
                'label'       => '显示小时',
                'default'     => true,
            ],
            'show_minutes' => [
                'type'        => 'bool',
                'label'       => '显示分钟',
                'default'     => true,
            ],
            'show_seconds' => [
                'type'        => 'bool',
                'label'       => '显示秒数',
                'default'     => true,
            ],
            'style' => [
                'type'        => 'select',
                'label'       => '样式',
                'default'     => 'boxes',
                'options'     => [
                    'boxes'  => '方框样式',
                    'inline' => '行内样式',
                    'circle' => '圆形样式',
                ],
            ],
        ],
    ],

    // 品牌Logo
    [
        'name'        => '品牌Logo',
        'description' => '品牌Logo展示，支持轮播',
        'type'        => 'content',
        'code'        => 'brand-logos',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => true,
        'page_layouts' => ['homepage', 'cms_page'],  // 首页和CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/content/brand-logos/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '合作品牌',
            ],
            'brands' => [
                'type'        => 'array',
                'label'       => '品牌列表',
                'default'     => [],
                'description' => '品牌数组，每项包含image、name、link',
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '6',
                'options'     => [
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                    '8' => '8列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'grid',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
            'grayscale' => [
                'type'        => 'bool',
                'label'       => '灰度显示',
                'default'     => true,
            ],
            'hover_color' => [
                'type'        => 'bool',
                'label'       => '悬停彩色',
                'default'     => true,
            ],
        ],
    ],

    // ==================== Footer 子部件（通用 - 所有页面） ====================

    // 页脚链接
    [
        'name'        => '页脚链接',
        'description' => '页脚链接列表，可分组显示',
        'type'        => 'footer',
        'code'        => 'footer-links',
        'area'        => 'frontend',
        'position'    => ['footer'],
        'compatible'  => true,
        'slot'        => 'links',  // 放入 Footer 容器的 links 插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/footer/footer-links/default.phtml',
        'params'      => [
            'link_groups' => [
                'type'        => 'array',
                'label'       => '链接分组',
                'default'     => [],
                'description' => '分组数组，每项包含title和links数组',
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                ],
            ],
        ],
    ],

    // 页脚邮件订阅
    [
        'name'        => '页脚邮件订阅',
        'description' => '页脚区域的邮件订阅表单',
        'type'        => 'newsletter',
        'code'        => 'footer-newsletter',
        'area'        => 'frontend',
        'position'    => ['footer'],
        'compatible'  => true,
        'exclusive'   => true,   // 独占 newsletter 插槽
        'slot'        => 'newsletter',  // 放入 Footer 容器的 newsletter 插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/newsletter/footer-newsletter/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '订阅我们的邮件',
            ],
            'description' => [
                'type'        => 'string',
                'label'       => '描述',
                'default'     => '获取最新的优惠信息和新品资讯',
            ],
            'placeholder' => [
                'type'        => 'string',
                'label'       => '输入框占位符',
                'default'     => '请输入您的邮箱地址',
            ],
            'button_text' => [
                'type'        => 'string',
                'label'       => '按钮文字',
                'default'     => '订阅',
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局',
                'default'     => 'horizontal',
                'options'     => [
                    'horizontal' => '水平布局',
                    'vertical'   => '垂直布局',
                ],
            ],
        ],
    ],

    // 页脚社交图标
    [
        'name'        => '页脚社交图标',
        'description' => '页脚社交媒体图标链接',
        'type'        => 'social',
        'code'        => 'footer-social',
        'area'        => 'frontend',
        'position'    => ['footer'],
        'compatible'  => true,
        'exclusive'   => true,   // 独占 social 插槽
        'slot'        => 'social',  // 放入 Footer 容器的 social 插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/social/footer-social/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '',
                'translatable' => true,  // 支持多语言
            ],
            'alignment' => [
                'type'        => 'select',
                'label'       => '对齐方式',
                'default'     => 'left',
                'options'     => [
                    'left'   => '左对齐',
                    'center' => '居中',
                    'right'  => '右对齐',
                ],
            ],
            'icon_size' => [
                'type'        => 'select',
                'label'       => '图标尺寸',
                'default'     => 'medium',
                'options'     => [
                    'small'  => '小号',
                    'medium' => '中号',
                    'large'  => '大号',
                ],
            ],
            'icon_style' => [
                'type'        => 'select',
                'label'       => '图标样式',
                'default'     => 'colored',
                'options'     => [
                    'colored' => '彩色',
                    'mono'    => '单色',
                    'outline' => '线框',
                ],
            ],
            'gap' => [
                'type'        => 'string',
                'label'       => '图标间距',
                'default'     => '10px',
                'description' => '支持CSS单位（如10px、1rem）',
            ],
            'custom_links' => [
                'type'        => 'string',
                'label'       => '自定义社交链接',
                'default'     => '',
                'description' => 'JSON格式，例：[{"platform":"facebook","url":"https://..."}]',
            ],
            'facebook' => [
                'type'        => 'url',
                'label'       => 'Facebook链接',
                'default'     => '',
            ],
            'twitter' => [
                'type'        => 'url',
                'label'       => 'Twitter/X链接',
                'default'     => '',
            ],
            'instagram' => [
                'type'        => 'url',
                'label'       => 'Instagram链接',
                'default'     => '',
            ],
            'youtube' => [
                'type'        => 'url',
                'label'       => 'YouTube链接',
                'default'     => '',
            ],
            'linkedin' => [
                'type'        => 'url',
                'label'       => 'LinkedIn链接',
                'default'     => '',
            ],
            'pinterest' => [
                'type'        => 'url',
                'label'       => 'Pinterest链接',
                'default'     => '',
            ],
            'tiktok' => [
                'type'        => 'url',
                'label'       => 'TikTok链接',
                'default'     => '',
            ],
            'weibo' => [
                'type'        => 'url',
                'label'       => '微博链接',
                'default'     => '',
            ],
            'wechat' => [
                'type'        => 'url',
                'label'       => '微信链接',
                'default'     => '',
            ],
            'github' => [
                'type'        => 'url',
                'label'       => 'GitHub链接',
                'default'     => '',
            ],
            'telegram' => [
                'type'        => 'url',
                'label'       => 'Telegram链接',
                'default'     => '',
            ],
            'whatsapp' => [
                'type'        => 'url',
                'label'       => 'WhatsApp链接',
                'default'     => '',
            ],
            'discord' => [
                'type'        => 'url',
                'label'       => 'Discord链接',
                'default'     => '',
            ],
            'reddit' => [
                'type'        => 'url',
                'label'       => 'Reddit链接',
                'default'     => '',
            ],
            'snapchat' => [
                'type'        => 'url',
                'label'       => 'Snapchat链接',
                'default'     => '',
            ],
        ],
    ],

    // 页脚支付图标
    [
        'name'        => '页脚支付图标',
        'description' => '支付方式图标展示',
        'type'        => 'footer',
        'code'        => 'footer-payment',
        'area'        => 'frontend',
        'position'    => ['footer'],
        'compatible'  => true,
        'exclusive'   => true,   // 独占 payment 插槽
        'slot'        => 'payment',  // 放入 Footer 容器的 payment 插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/footer/footer-payment/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '支付方式',
            ],
            'payment_methods' => [
                'type'        => 'array',
                'label'       => '支付方式',
                'default'     => ['visa', 'mastercard', 'paypal', 'alipay', 'wechat'],
                'description' => '支持的值：visa, mastercard, amex, paypal, alipay, wechat, unionpay, jcb, discover, applepay, googlepay',
            ],
            'show_label' => [
                'type'        => 'bool',
                'label'       => '显示标签',
                'default'     => true,
            ],
        ],
    ],

    // 页脚版权
    [
        'name'        => '页脚版权',
        'description' => '版权信息和备案号展示',
        'type'        => 'footer',
        'code'        => 'footer-copyright',
        'area'        => 'frontend',
        'position'    => ['footer'],
        'compatible'  => true,
        'exclusive'   => true,   // 独占 copyright 插槽
        'slot'        => 'copyright',  // 放入 Footer 容器的 copyright 插槽
        'page_layouts' => ['*'],  // 所有页面类型
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/footer/footer-copyright/default.phtml',
        'params'      => [
            'copyright_text' => [
                'type'        => 'string',
                'label'       => '版权文字',
                'default'     => '© {year} {site_name}. All Rights Reserved.',
                'description' => '支持变量：{year}当前年份，{site_name}网站名称',
            ],
            'icp_number' => [
                'type'        => 'string',
                'label'       => 'ICP备案号',
                'default'     => '',
            ],
            'icp_link' => [
                'type'        => 'url',
                'label'       => '备案查询链接',
                'default'     => 'https://beian.miit.gov.cn/',
            ],
            'police_number' => [
                'type'        => 'string',
                'label'       => '公安备案号',
                'default'     => '',
            ],
            'police_link' => [
                'type'        => 'url',
                'label'       => '公安备案链接',
                'default'     => '',
            ],
            'additional_text' => [
                'type'        => 'string',
                'label'       => '附加文字',
                'default'     => '',
            ],
        ],
    ],

    // ==================== Social/Marketing 部件 ====================

    // 社交分享
    [
        'name'        => '社交分享',
        'description' => '社交媒体分享按钮',
        'type'        => 'social',
        'code'        => 'social-share',
        'area'        => 'frontend',
        'position'    => ['content', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['product', 'cms_page'],  // 产品页和CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/social/social-share/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '分享到',
            ],
            'platforms' => [
                'type'        => 'array',
                'label'       => '分享平台',
                'default'     => ['facebook', 'twitter', 'linkedin', 'whatsapp', 'email'],
                'description' => '支持：facebook, twitter, linkedin, pinterest, whatsapp, telegram, wechat, weibo, qq, email, copy',
            ],
            'style' => [
                'type'        => 'select',
                'label'       => '按钮样式',
                'default'     => 'icon',
                'options'     => [
                    'icon'      => '仅图标',
                    'text'      => '仅文字',
                    'icon-text' => '图标+文字',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局',
                'default'     => 'horizontal',
                'options'     => [
                    'horizontal' => '水平',
                    'vertical'   => '垂直',
                ],
            ],
        ],
    ],

    // 订阅弹窗
    [
        'name'        => '订阅弹窗',
        'description' => '邮件订阅弹窗，可设置触发条件',
        'type'        => 'newsletter',
        'code'        => 'newsletter-popup',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page'],  // 首页和CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/newsletter/newsletter-popup/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '订阅获取优惠',
            ],
            'description' => [
                'type'        => 'string',
                'label'       => '描述',
                'default'     => '订阅我们的邮件，立即获得10%折扣码！',
            ],
            'image' => [
                'type'        => 'image',
                'label'       => '弹窗图片',
                'default'     => '',
            ],
            'button_text' => [
                'type'        => 'string',
                'label'       => '按钮文字',
                'default'     => '立即订阅',
            ],
            'trigger' => [
                'type'        => 'select',
                'label'       => '触发方式',
                'default'     => 'delay',
                'options'     => [
                    'delay'       => '延时触发',
                    'scroll'      => '滚动触发',
                    'exit-intent' => '退出意图',
                ],
            ],
            'delay_seconds' => [
                'type'        => 'number',
                'label'       => '延时秒数',
                'default'     => 5,
                'min'         => 1,
                'max'         => 60,
            ],
            'scroll_percent' => [
                'type'        => 'number',
                'label'       => '滚动百分比',
                'default'     => 50,
                'min'         => 10,
                'max'         => 100,
            ],
            'show_once' => [
                'type'        => 'bool',
                'label'       => '只显示一次',
                'default'     => true,
            ],
            'cookie_days' => [
                'type'        => 'number',
                'label'       => 'Cookie有效天数',
                'default'     => 7,
                'min'         => 1,
                'max'         => 365,
            ],
        ],
    ],

    // 客户评价
    [
        'name'        => '客户评价',
        'description' => '客户评价/证言展示',
        'type'        => 'testimonial',
        'code'        => 'testimonials',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['homepage', 'cms_page'],  // 首页和CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/testimonial/testimonials/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '客户评价',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '看看客户怎么说',
            ],
            'testimonials' => [
                'type'        => 'array',
                'label'       => '评价列表',
                'default'     => [],
                'description' => '评价数组，每项包含content、author、position、avatar、rating',
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '3',
                'options'     => [
                    '1' => '1列',
                    '2' => '2列',
                    '3' => '3列',
                ],
            ],
            'layout' => [
                'type'        => 'select',
                'label'       => '布局方式',
                'default'     => 'grid',
                'options'     => [
                    'grid'     => '网格布局',
                    'carousel' => '轮播布局',
                ],
            ],
            'show_rating' => [
                'type'        => 'bool',
                'label'       => '显示评分',
                'default'     => true,
            ],
            'show_avatar' => [
                'type'        => 'bool',
                'label'       => '显示头像',
                'default'     => true,
            ],
        ],
    ],

    // 信任徽章
    [
        'name'        => '信任徽章',
        'description' => '安全认证、支付保障等信任标识',
        'type'        => 'content',
        'code'        => 'trust-badges',
        'area'        => 'frontend',
        'position'    => ['content', 'footer'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/content/trust-badges/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '',
            ],
            'badges' => [
                'type'        => 'array',
                'label'       => '徽章列表',
                'default'     => [],
                'description' => '徽章数组，每项包含icon、title、description',
            ],
            'preset_badges' => [
                'type'        => 'array',
                'label'       => '预设徽章',
                'default'     => ['secure-payment', 'money-back', 'free-shipping', '24-7-support'],
                'description' => '预设值：secure-payment, money-back, free-shipping, 24-7-support, ssl-secure, verified-seller',
            ],
            'columns' => [
                'type'        => 'select',
                'label'       => '每行列数',
                'default'     => '4',
                'options'     => [
                    '2' => '2列',
                    '3' => '3列',
                    '4' => '4列',
                    '6' => '6列',
                ],
            ],
            'style' => [
                'type'        => 'select',
                'label'       => '样式',
                'default'     => 'icon-text',
                'options'     => [
                    'icon-only' => '仅图标',
                    'icon-text' => '图标+文字',
                    'card'      => '卡片样式',
                ],
            ],
        ],
    ],

    // FAQ折叠面板
    [
        'name'        => 'FAQ折叠面板',
        'description' => '常见问题折叠面板',
        'type'        => 'faq',
        'code'        => 'faq-accordion',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => false,
        'page_layouts' => ['cms_page', 'product'],  // CMS页和产品页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/faq/faq-accordion/default.phtml',
        'params'      => [
            'title' => [
                'type'        => 'string',
                'label'       => '标题',
                'default'     => '常见问题',
            ],
            'subtitle' => [
                'type'        => 'string',
                'label'       => '副标题',
                'default'     => '',
            ],
            'faqs' => [
                'type'        => 'array',
                'label'       => 'FAQ列表',
                'default'     => [],
                'description' => 'FAQ数组，每项包含question和answer',
            ],
            'expand_first' => [
                'type'        => 'bool',
                'label'       => '默认展开第一个',
                'default'     => true,
            ],
            'allow_multiple' => [
                'type'        => 'bool',
                'label'       => '允许同时展开多个',
                'default'     => false,
            ],
            'search_enabled' => [
                'type'        => 'bool',
                'label'       => '启用搜索',
                'default'     => false,
            ],
        ],
    ],

    // ==================== 通用部件 ====================

    // 面包屑导航
    [
        'name'        => '面包屑导航',
        'description' => '页面路径面包屑导航',
        'type'        => 'breadcrumb',
        'code'        => 'breadcrumb',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/breadcrumb/breadcrumb/default.phtml',
        'params'      => [
            'show_home' => [
                'type'        => 'bool',
                'label'       => '显示首页',
                'default'     => true,
            ],
            'home_text' => [
                'type'        => 'string',
                'label'       => '首页文字',
                'default'     => '首页',
            ],
            'home_icon' => [
                'type'        => 'bool',
                'label'       => '首页使用图标',
                'default'     => true,
            ],
            'separator' => [
                'type'        => 'string',
                'label'       => '分隔符',
                'default'     => '/',
            ],
            'show_current' => [
                'type'        => 'bool',
                'label'       => '显示当前页',
                'default'     => true,
            ],
        ],
    ],

    // 搜索栏
    [
        'name'        => '搜索栏',
        'description' => '通用搜索栏组件，可放置在任意位置',
        'type'        => 'search',
        'code'        => 'search-bar',
        'area'        => 'frontend',
        'position'    => ['content', 'sidebar'],
        'compatible'  => true,
        'page_layouts' => ['*'],  // 所有页面类型（通用）
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/search/search-bar/default.phtml',
        'params'      => [
            'placeholder' => [
                'type'        => 'string',
                'label'       => '占位符',
                'default'     => '搜索...',
            ],
            'action_url' => [
                'type'        => 'url',
                'label'       => '搜索地址',
                'default'     => '/search',
            ],
            'show_button' => [
                'type'        => 'bool',
                'label'       => '显示搜索按钮',
                'default'     => true,
            ],
            'button_text' => [
                'type'        => 'string',
                'label'       => '按钮文字',
                'default'     => '',
                'description' => '留空则显示图标',
            ],
            'style' => [
                'type'        => 'select',
                'label'       => '样式',
                'default'     => 'default',
                'options'     => [
                    'default' => '默认',
                    'rounded' => '圆角',
                    'minimal' => '极简',
                ],
            ],
        ],
    ],

    // 分页
    [
        'name'        => '分页',
        'description' => '列表分页组件',
        'type'        => 'pagination',
        'code'        => 'pagination',
        'area'        => 'frontend',
        'position'    => ['content'],
        'compatible'  => true,
        'page_layouts' => ['category', 'search', 'cms_page'],  // 分类、搜索、CMS页布局
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/pagination/pagination/default.phtml',
        'params'      => [
            'style' => [
                'type'        => 'select',
                'label'       => '样式',
                'default'     => 'default',
                'options'     => [
                    'default' => '默认',
                    'simple'  => '简洁',
                    'rounded' => '圆角',
                ],
            ],
            'show_first_last' => [
                'type'        => 'bool',
                'label'       => '显示首页/末页',
                'default'     => true,
            ],
            'show_prev_next' => [
                'type'        => 'bool',
                'label'       => '显示上一页/下一页',
                'default'     => true,
            ],
            'page_range' => [
                'type'        => 'number',
                'label'       => '显示页码数',
                'default'     => 5,
                'min'         => 3,
                'max'         => 10,
            ],
            'prev_text' => [
                'type'        => 'string',
                'label'       => '上一页文字',
                'default'     => '上一页',
            ],
            'next_text' => [
                'type'        => 'string',
                'label'       => '下一页文字',
                'default'     => '下一页',
            ],
        ],
    ],
];
