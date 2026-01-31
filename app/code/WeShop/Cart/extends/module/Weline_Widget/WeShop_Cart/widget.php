<?php
/**
 * WeShop_Cart 模块 Widget 配置
 * 
 * 本文件定义了 WeShop_Cart 模块提供的所有 Widget 部件
 */
return [
    // ==================== MiniCart Widget ====================
    [
        'name' => '迷你购物车（侧边抽屉）',
        'description' => 'Shopify 风格侧边弹出式购物车，支持实时更新和交互操作',
        'type' => 'mini-cart',
        'code' => 'mini-cart-drawer',
        'template' => 'WeShop_Cart::theme/frontend/widgets/mini-cart/drawer.phtml',
        'area' => 'frontend',
        'params' => [
            'position' => [
                'type' => 'select',
                'default' => 'right',
                'options' => ['left' => '左侧', 'right' => '右侧'],
                'name' => '弹出位置',
                'description' => '迷你购物车从哪侧弹出',
            ],
            'width' => [
                'type' => 'text',
                'default' => '400px',
                'name' => '宽度',
                'description' => '迷你购物车宽度，支持 px、rem、% 等单位',
            ],
            'showEmptyState' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '显示空状态',
                'description' => '购物车为空时是否显示空状态提示',
            ],
            'autoOpen' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '添加商品后自动打开',
                'description' => '添加商品到购物车后是否自动打开迷你购物车',
            ],
        ],
    ],
    
    // ==================== Cart Icon Widget ====================
    [
        'name' => '购物车图标',
        'description' => 'Header 区域购物车图标按钮，显示购物车数量徽章',
        'type' => 'header',
        'code' => 'cart-icon',
        'template' => 'WeShop_Cart::theme/frontend/widgets/header/cart-icon.phtml',
        'area' => 'frontend',
        'params' => [
            'showCount' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '显示数量',
                'description' => '是否显示购物车商品数量徽章',
            ],
            'showLabel' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '显示文字',
                'description' => '是否显示"购物车"文字标签',
            ],
            'triggerAction' => [
                'type' => 'select',
                'default' => 'drawer',
                'options' => ['drawer' => '打开侧边抽屉', 'link' => '跳转购物车页面'],
                'name' => '点击行为',
                'description' => '点击购物车图标的行为',
            ],
        ],
    ],
    
    // ==================== Cart Summary Widget ====================
    [
        'name' => '购物车摘要',
        'description' => '购物车页面订单摘要卡片，显示小计、运费、税费、总计等',
        'type' => 'summary',
        'code' => 'cart-summary',
        'template' => 'WeShop_Cart::theme/frontend/widgets/cart/summary.phtml',
        'area' => 'frontend',
        'params' => [
            'showShipping' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '显示运费',
            ],
            'showTax' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '显示税费',
            ],
            'showCoupon' => [
                'type' => 'boolean',
                'default' => true,
                'name' => '显示优惠券',
            ],
        ],
    ],
];
