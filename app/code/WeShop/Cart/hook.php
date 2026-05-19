<?php
/**
 * WeShop_Cart 模块 Hook 规约文件
 * 
 * 本文件定义了 WeShop_Cart 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== Cart Page Layout Hooks ====================
    'WeShop_Cart::frontend::layouts::cart::head-after' => [
        'name' => __('购物车页面头部之后'),
        'description' => __('在购物车页面 head 标签结束前插入内容，可用于添加购物车专用的CSS/JS。'),
        'doc' => 'frontend/layouts/cart/head-after.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::body-start' => [
        'name' => __('购物车页面 body 开始'),
        'description' => __('在购物车页面 body 标签开始后插入内容。'),
        'doc' => 'frontend/layouts/cart/body-start.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::content-before' => [
        'name' => __('购物车内容区域之前'),
        'description' => __('在购物车主内容区域之前插入内容，可用于显示通知横幅。'),
        'doc' => 'frontend/layouts/cart/content-before.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::content-after' => [
        'name' => __('购物车内容区域之后'),
        'description' => __('在购物车主内容区域之后插入内容。'),
        'doc' => 'frontend/layouts/cart/content-after.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::body-end' => [
        'name' => __('购物车页面 body 结束'),
        'description' => __('在购物车页面 body 标签结束前插入内容。'),
        'doc' => 'frontend/layouts/cart/body-end.md',
    ],
    
    // ==================== Cart Page Partials Hooks ====================
    'WeShop_Cart::frontend::partials::cart::header-before' => [
        'name' => __('购物车标题之前'),
        'description' => __('在购物车页面标题之前插入内容。'),
        'doc' => 'frontend/partials/cart/header-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart::header-after' => [
        'name' => __('购物车标题之后'),
        'description' => __('在购物车页面标题之后插入内容。'),
        'doc' => 'frontend/partials/cart/header-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart::items-before' => [
        'name' => __('购物车商品列表前'),
        'description' => __('在购物车商品列表前渲染内容，可用于添加提示信息、横幅广告。'),
        'doc' => 'frontend/partials/cart/items-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart::items-after' => [
        'name' => __('购物车商品列表后'),
        'description' => __('在购物车商品列表后渲染内容，可用于推荐商品、关联商品。'),
        'doc' => 'frontend/partials/cart/items-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart::item-before' => [
        'name' => __('购物车单个商品前'),
        'description' => __('在购物车每个商品前渲染内容。'),
        'doc' => 'frontend/partials/cart/item-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart::item-after' => [
        'name' => __('购物车单个商品后'),
        'description' => __('在购物车每个商品后渲染内容，可用于添加赠品、促销信息等。'),
        'doc' => 'frontend/partials/cart/item-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart::continue-shopping' => [
        'name' => __('继续购物按钮'),
        'description' => __('继续购物按钮区域，可自定义返回链接。'),
        'doc' => 'frontend/partials/cart/continue-shopping.md',
    ],
    'WeShop_Cart::frontend::partials::cart::summary-before' => [
        'name' => __('订单摘要之前'),
        'description' => __('在订单摘要侧边栏之前渲染内容。'),
        'doc' => 'frontend/partials/cart/summary-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart::summary-after' => [
        'name' => __('订单摘要之后'),
        'description' => __('在订单摘要侧边栏之后渲染内容。'),
        'doc' => 'frontend/partials/cart/summary-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart::coupon-input' => [
        'name' => __('优惠券输入框'),
        'description' => __('优惠券输入框区域，WeShop_Coupon 模块可实现此 Hook 提供完整优惠券功能。'),
        'doc' => 'frontend/partials/cart/coupon-input.md',
    ],
    'WeShop_Cart::frontend::partials::cart::shipping-options' => [
        'name' => __('配送方式选择'),
        'description' => __('配送方式选择区域，WeShop_Shipping 模块可实现此 Hook。'),
        'doc' => 'frontend/partials/cart/shipping-options.md',
    ],
    'WeShop_Cart::frontend::partials::cart::discount-display' => [
        'name' => __('折扣显示'),
        'description' => __('折扣金额显示区域，用于显示优惠券、促销活动折扣。'),
        'doc' => 'frontend/partials/cart/discount-display.md',
    ],
    'WeShop_Cart::frontend::partials::cart::express-checkout' => [
        'name' => __('快捷支付'),
        'description' => __('快捷支付按钮区域，如 PayPal Express、Apple Pay 等。'),
        'doc' => 'frontend/partials/cart/express-checkout.md',
    ],
    'WeShop_Cart::frontend::partials::cart::security-badges' => [
        'name' => __('安全保障标识'),
        'description' => __('安全保障信息显示区域。'),
        'doc' => 'frontend/partials/cart/security-badges.md',
    ],
    'WeShop_Cart::frontend::partials::cart::sidebar' => [
        'name' => __('购物车侧边栏扩展'),
        'description' => __('购物车页面侧边栏扩展区域，可用于展示促销信息、推荐商品等。'),
        'doc' => 'frontend/partials/cart/sidebar-ext.md',
    ],
    'WeShop_Cart::frontend::partials::cart::empty' => [
        'name' => __('空购物车状态'),
        'description' => __('空购物车状态显示区域，可自定义空购物车提示。'),
        'doc' => 'frontend/partials/cart/empty.md',
    ],
    
    // ==================== Cart Page Content Hooks ====================
    'WeShop_Cart::frontend::layouts::cart::page-before' => [
        'name' => __('购物车页面内容前'),
        'description' => __('在购物车页面主体内容开始前渲染内容。'),
        'doc' => 'frontend/page/before.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::page-after' => [
        'name' => __('购物车页面内容后'),
        'description' => __('在购物车页面主体内容结束后渲染内容。'),
        'doc' => 'frontend/page/after.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::items-after' => [
        'name' => __('购物车商品区域后'),
        'description' => __('在购物车商品列表区域之后、摘要区域之前渲染内容。'),
        'doc' => 'frontend/page/items-after.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::related-before' => [
        'name' => __('购物车推荐商品前'),
        'description' => __('在购物车推荐商品区域之前渲染内容。'),
        'doc' => 'frontend/page/related-before.md',
    ],
    'WeShop_Cart::frontend::layouts::cart::related-after' => [
        'name' => __('购物车推荐商品后'),
        'description' => __('在购物车推荐商品区域之后渲染内容。'),
        'doc' => 'frontend/page/related-after.md',
    ],

    // ==================== Cart Summary Hooks ====================
    'WeShop_Cart::frontend::partials::cart-summary::before' => [
        'name' => __('购物车摘要前'),
        'description' => __('在购物车订单摘要卡片内容前渲染内容。'),
        'doc' => 'frontend/summary/before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::after' => [
        'name' => __('购物车摘要后'),
        'description' => __('在购物车订单摘要卡片内容后渲染内容。'),
        'doc' => 'frontend/summary/after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::rows-before' => [
        'name' => __('购物车摘要行前'),
        'description' => __('在购物车摘要金额行之前渲染内容。'),
        'doc' => 'frontend/summary/rows-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::rows-after' => [
        'name' => __('购物车摘要行后'),
        'description' => __('在购物车摘要金额行之后渲染内容。'),
        'doc' => 'frontend/summary/rows-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::subtotal-before' => [
        'name' => __('购物车小计前'),
        'description' => __('在购物车小计行之前渲染内容。'),
        'doc' => 'frontend/summary/subtotal-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::subtotal-after' => [
        'name' => __('购物车小计后'),
        'description' => __('在购物车小计行之后渲染内容。'),
        'doc' => 'frontend/summary/subtotal-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::shipping-before' => [
        'name' => __('购物车配送前'),
        'description' => __('在购物车配送金额行之前渲染内容。'),
        'doc' => 'frontend/summary/shipping-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::shipping-after' => [
        'name' => __('购物车配送后'),
        'description' => __('在购物车配送金额行之后渲染内容。'),
        'doc' => 'frontend/summary/shipping-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::discount-before' => [
        'name' => __('购物车折扣前'),
        'description' => __('在购物车折扣金额行之前渲染内容。'),
        'doc' => 'frontend/summary/discount-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::discount-after' => [
        'name' => __('购物车折扣后'),
        'description' => __('在购物车折扣金额行之后渲染内容。'),
        'doc' => 'frontend/summary/discount-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::tax-before' => [
        'name' => __('购物车税费前'),
        'description' => __('在购物车税费金额行之前渲染内容。'),
        'doc' => 'frontend/summary/tax-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::tax-after' => [
        'name' => __('购物车税费后'),
        'description' => __('在购物车税费金额行之后渲染内容。'),
        'doc' => 'frontend/summary/tax-after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::grand-total-before' => [
        'name' => __('购物车总计前'),
        'description' => __('在购物车总计金额行之前渲染内容。'),
        'doc' => 'frontend/summary/grand-total-before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-summary::grand-total-after' => [
        'name' => __('购物车总计后'),
        'description' => __('在购物车总计金额行之后渲染内容。'),
        'doc' => 'frontend/summary/grand-total-after.md',
    ],

    // ==================== Mini Cart Partials Hooks ====================
    'WeShop_Cart::frontend::partials::mini-cart::header-before' => [
        'name' => __('迷你购物车头部之前'),
        'description' => __('在迷你购物车头部之前插入内容。'),
        'doc' => 'frontend/partials/mini-cart/header-before.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::header-after' => [
        'name' => __('迷你购物车头部之后'),
        'description' => __('在迷你购物车头部之后插入内容，如促销信息横幅。'),
        'doc' => 'frontend/partials/mini-cart/header-after.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::items-before' => [
        'name' => __('迷你购物车商品列表前'),
        'description' => __('在迷你购物车商品列表前渲染内容，如免邮提示。'),
        'doc' => 'frontend/partials/mini-cart/items-before.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::shipping-progress' => [
        'name' => __('迷你购物车免邮进度'),
        'description' => __('在迷你购物车商品列表前渲染免邮或促销进度内容。'),
        'doc' => 'frontend/partials/mini-cart/shipping-progress.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::items-after' => [
        'name' => __('迷你购物车商品列表后'),
        'description' => __('在迷你购物车商品列表后渲染内容，如推荐商品。'),
        'doc' => 'frontend/partials/mini-cart/items-after.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::footer-before' => [
        'name' => __('迷你购物车底部之前'),
        'description' => __('在迷你购物车底部之前插入内容。'),
        'doc' => 'frontend/partials/mini-cart/footer-before.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::footer-after' => [
        'name' => __('迷你购物车底部之后'),
        'description' => __('在迷你购物车底部之后插入内容。'),
        'doc' => 'frontend/partials/mini-cart/footer-after.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::express-checkout' => [
        'name' => __('迷你购物车快捷支付'),
        'description' => __('在迷你购物车底部操作后渲染快捷支付或外部支付入口。'),
        'doc' => 'frontend/partials/mini-cart/express-checkout.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart::empty' => [
        'name' => __('迷你购物车空状态'),
        'description' => __('迷你购物车空状态显示区域。'),
        'doc' => 'frontend/partials/mini-cart/empty.md',
    ],
    
    // ==================== Header Cart Hook ====================
    // 注意：'header-cart' Hook 由 Weline_Theme 定义，WeShop_Cart 实现它
    'WeShop_Cart::frontend::partials::header::mini-cart' => [
        'name' => __('WeShop 页眉迷你购物车'),
        'description' => __('WeShop 主题页眉中的迷你购物车区域。'),
        'doc' => 'frontend/partials/header/mini-cart.md',
    ],
    'WeShop_Cart::frontend::partials::header::mini-cart-items' => [
        'name' => __('页眉迷你购物车商品列表'),
        'description' => __('页眉下拉迷你购物车中的商品列表区域。'),
        'doc' => 'frontend/partials/header/mini-cart-items.md',
    ],
    
    // ==================== Legacy Hooks (兼容) ====================
    'WeShop_Cart::frontend::partials::cart-items::before' => [
        'name' => __('购物车商品列表前（兼容）'),
        'description' => __('兼容旧版 Hook，请使用 WeShop_Cart::frontend::cart::items-before。'),
        'doc' => 'frontend/partials/cart-items/before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-items::after' => [
        'name' => __('购物车商品列表后（兼容）'),
        'description' => __('兼容旧版 Hook，请使用 WeShop_Cart::frontend::cart::items-after。'),
        'doc' => 'frontend/partials/cart-items/after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-item::after' => [
        'name' => __('购物车单个商品后（兼容）'),
        'description' => __('兼容旧版 Hook，请使用 WeShop_Cart::frontend::cart::item-after。'),
        'doc' => 'frontend/partials/cart-item/after.md',
    ],
    'WeShop_Cart::frontend::partials::cart-totals::before' => [
        'name' => __('购物车总计前（兼容）'),
        'description' => __('兼容旧版 Hook。'),
        'doc' => 'frontend/partials/cart-totals/before.md',
    ],
    'WeShop_Cart::frontend::partials::cart-totals::after' => [
        'name' => __('购物车总计后（兼容）'),
        'description' => __('兼容旧版 Hook。'),
        'doc' => 'frontend/partials/cart-totals/after.md',
    ],
    'WeShop_Cart::frontend::partials::cart::sidebar' => [
        'name' => __('购物车侧边栏（兼容）'),
        'description' => __('兼容旧版 Hook，请使用 WeShop_Cart::frontend::cart::sidebar。'),
        'doc' => 'frontend/partials/cart/sidebar.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart-items::before' => [
        'name' => __('迷你购物车商品列表前（兼容）'),
        'description' => __('兼容旧版 Hook，请使用 WeShop_Cart::frontend::mini-cart::items-before。'),
        'doc' => 'frontend/partials/mini-cart-items/before.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart-items::after' => [
        'name' => __('迷你购物车商品列表后（兼容）'),
        'description' => __('兼容旧版 Hook，请使用 WeShop_Cart::frontend::mini-cart::items-after。'),
        'doc' => 'frontend/partials/mini-cart-items/after.md',
    ],
];
