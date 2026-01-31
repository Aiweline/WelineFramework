<?php
/**
 * WeShop_Cart 模块事件定义文件
 * 
 * 本文件定义了 WeShop_Cart 模块提供的所有事件
 * 其他模块可以通过 etc/event.xml 监听这些事件
 * 
 * 事件命名格式：{ModuleName}::{action}_{timing}
 * 例如：WeShop_Cart::add_to_cart_before
 */
return [
    // ==================== 添加到购物车事件 ====================
    'WeShop_Cart::add_to_cart_before' => [
        'name' => __('添加到购物车之前'),
        'description' => __('在商品添加到购物车之前触发，可用于验证库存、检查限购等。'),
        'doc' => 'event/add_to_cart_before.md',
        'data' => [
            'customer_id' => 'int - 客户ID',
            'product_id' => 'int - 产品ID',
            'quantity' => 'int - 添加数量',
            'options' => 'array - 产品选项（可选）',
        ],
    ],
    'WeShop_Cart::add_to_cart_after' => [
        'name' => __('添加到购物车之后'),
        'description' => __('在商品添加到购物车之后触发，可用于更新统计、发送通知、触发前端刷新等。'),
        'doc' => 'event/add_to_cart_after.md',
        'data' => [
            'cart' => 'Cart - 购物车模型实例',
            'customer_id' => 'int - 客户ID',
            'product_id' => 'int - 产品ID',
            'quantity' => 'int - 添加数量',
        ],
    ],
    
    // ==================== 更新购物车事件 ====================
    'WeShop_Cart::update_cart_before' => [
        'name' => __('更新购物车之前'),
        'description' => __('在更新购物车商品数量之前触发。'),
        'doc' => 'event/update_cart_before.md',
        'data' => [
            'cart_id' => 'int - 购物车项ID',
            'quantity' => 'int - 新数量',
            'customer_id' => 'int - 客户ID',
        ],
    ],
    'WeShop_Cart::update_cart_after' => [
        'name' => __('更新购物车之后'),
        'description' => __('在更新购物车商品数量之后触发，可用于重新计算总额、更新前端显示等。'),
        'doc' => 'event/update_cart_after.md',
        'data' => [
            'cart' => 'Cart - 购物车模型实例',
            'cart_id' => 'int - 购物车项ID',
            'quantity' => 'int - 新数量',
        ],
    ],
    
    // ==================== 从购物车移除事件 ====================
    'WeShop_Cart::remove_from_cart_before' => [
        'name' => __('从购物车移除之前'),
        'description' => __('在从购物车移除商品之前触发。'),
        'doc' => 'event/remove_from_cart_before.md',
        'data' => [
            'cart_id' => 'int - 购物车项ID',
            'customer_id' => 'int - 客户ID',
        ],
    ],
    'WeShop_Cart::remove_from_cart_after' => [
        'name' => __('从购物车移除之后'),
        'description' => __('在从购物车移除商品之后触发，可用于更新统计、触发前端刷新等。'),
        'doc' => 'event/remove_from_cart_after.md',
        'data' => [
            'cart_id' => 'int - 已删除的购物车项ID',
            'customer_id' => 'int - 客户ID',
        ],
    ],
    
    // ==================== 清空购物车事件 ====================
    'WeShop_Cart::clear_before' => [
        'name' => __('清空购物车之前'),
        'description' => __('在清空购物车之前触发。'),
        'doc' => 'event/clear_before.md',
        'data' => [
            'customer_id' => 'int - 客户ID',
        ],
    ],
    'WeShop_Cart::clear_after' => [
        'name' => __('清空购物车之后'),
        'description' => __('在清空购物车之后触发，通常在订单完成后调用。'),
        'doc' => 'event/clear_after.md',
        'data' => [
            'customer_id' => 'int - 客户ID',
        ],
    ],
    
    // ==================== 购物车总额计算事件 ====================
    'WeShop_Cart::totals_collect' => [
        'name' => __('购物车总额收集'),
        'description' => __('在计算购物车总额时触发，其他模块可以添加运费、税费、折扣等。'),
        'doc' => 'event/totals_collect.md',
        'data' => [
            'customer_id' => 'int - 客户ID',
            'items' => 'array - 购物车商品列表',
            'totals' => 'array& - 总额数组（引用传递，可修改）',
        ],
    ],
    'WeShop_Cart::totals_collected' => [
        'name' => __('购物车总额已收集'),
        'description' => __('在购物车总额计算完成后触发，可用于记录日志、分析等。'),
        'doc' => 'event/totals_collected.md',
        'data' => [
            'customer_id' => 'int - 客户ID',
            'totals' => 'array - 最终总额数组',
        ],
    ],
    
    // ==================== 购物车数据加载事件 ====================
    'WeShop_Cart::cart_loaded' => [
        'name' => __('购物车数据已加载'),
        'description' => __('在购物车数据加载完成后触发，可用于添加额外数据、修改显示内容等。'),
        'doc' => 'event/cart_loaded.md',
        'data' => [
            'customer_id' => 'int - 客户ID',
            'items' => 'array - 购物车商品列表',
            'totals' => 'array - 总额数组',
        ],
    ],
    
    // ==================== 优惠券事件 ====================
    'WeShop_Cart::coupon_apply_before' => [
        'name' => __('应用优惠券之前'),
        'description' => __('在应用优惠券之前触发，WeShop_Coupon 模块应监听此事件。'),
        'data' => [
            'customer_id' => 'int - 客户ID',
            'coupon_code' => 'string - 优惠券代码',
        ],
    ],
    'WeShop_Cart::coupon_apply_after' => [
        'name' => __('应用优惠券之后'),
        'description' => __('在应用优惠券之后触发。'),
        'data' => [
            'customer_id' => 'int - 客户ID',
            'coupon_code' => 'string - 优惠券代码',
            'discount' => 'float - 折扣金额',
            'success' => 'bool - 是否成功',
        ],
    ],
    
    // ==================== Mini Cart 事件 ====================
    'WeShop_Cart::mini_cart_loaded' => [
        'name' => __('迷你购物车已加载'),
        'description' => __('在迷你购物车数据加载完成后触发，用于 AJAX 请求响应。'),
        'data' => [
            'customer_id' => 'int - 客户ID',
            'items' => 'array - 购物车商品列表',
            'totals' => 'array - 总额数组',
            'html' => 'string - 渲染后的 HTML（可选）',
        ],
    ],
];
