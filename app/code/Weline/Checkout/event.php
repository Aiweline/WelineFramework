<?php
return [
    // ========== 结账事件 ==========
    
    // 结账数据验证前
    'Weline_Checkout::checkout::validate::before' => [
        'name' => __('结账数据验证前'),
        'description' => __('在验证结账数据之前触发，允许其他模块修改验证数据或执行预处理操作。'),
        'doc' => 'checkout/结账数据验证前.md',
    ],
    
    // 结账数据验证后
    'Weline_Checkout::checkout::validate::after' => [
        'name' => __('结账数据验证后'),
        'description' => __('在验证结账数据之后触发，允许其他模块根据验证结果执行后续操作。'),
        'doc' => 'checkout/结账数据验证后.md',
    ],
    
    // 计算总额前
    'Weline_Checkout::checkout::calculate_totals::before' => [
        'name' => __('计算订单总额前'),
        'description' => __('在计算订单总额之前触发，允许其他模块修改计算参数（运费、税费、折扣等）。'),
        'doc' => 'checkout/计算订单总额前.md',
    ],
    
    // 计算总额后
    'Weline_Checkout::checkout::calculate_totals::after' => [
        'name' => __('计算订单总额后'),
        'description' => __('在计算订单总额之后触发，允许其他模块修改计算结果或执行后续操作。'),
        'doc' => 'checkout/计算订单总额后.md',
    ],
    
    // 创建订单前
    'Weline_Checkout::checkout::create_order::before' => [
        'name' => __('创建订单前'),
        'description' => __('在创建订单之前触发，允许其他模块修改订单数据、验证库存、应用优惠券等。'),
        'doc' => 'checkout/创建订单前.md',
    ],
    
    // 创建订单后
    'Weline_Checkout::checkout::create_order::after' => [
        'name' => __('创建订单后'),
        'description' => __('在创建订单之后触发，允许其他模块执行后续操作，如扣减库存、发送通知、发起支付等。'),
        'doc' => 'checkout/创建订单后.md',
    ],
    
    // ========== 订单事件 ==========
    
    // 订单加载前
    'Weline_Checkout::order::load::before' => [
        'name' => __('订单加载前'),
        'description' => __('在加载订单数据之前触发，允许其他模块执行预处理操作。'),
        'doc' => 'order/订单加载前.md',
    ],
    
    // 订单加载后
    'Weline_Checkout::order::load::after' => [
        'name' => __('订单加载后'),
        'description' => __('在加载订单数据之后触发，允许其他模块修改订单数据或执行后续操作。'),
        'doc' => 'order/订单加载后.md',
    ],
    
    // 订单状态变更前
    'Weline_Checkout::order::status::change::before' => [
        'name' => __('订单状态变更前'),
        'description' => __('在订单状态变更之前触发，允许其他模块验证状态变更是否允许或执行预处理操作。'),
        'doc' => 'order/订单状态变更前.md',
    ],
    
    // 订单状态变更后
    'Weline_Checkout::order::status::change::after' => [
        'name' => __('订单状态变更后'),
        'description' => __('在订单状态变更之后触发，允许其他模块根据状态变化执行相应操作，如发送通知、更新库存等。'),
        'doc' => 'order/订单状态变更后.md',
    ],
    
    // 订单取消前
    'Weline_Checkout::order::cancel::before' => [
        'name' => __('订单取消前'),
        'description' => __('在取消订单之前触发，允许其他模块验证是否可以取消或执行预处理操作。'),
        'doc' => 'order/订单取消前.md',
    ],
    
    // 订单取消后
    'Weline_Checkout::order::cancel::after' => [
        'name' => __('订单取消后'),
        'description' => __('在取消订单之后触发，允许其他模块执行后续操作，如恢复库存、处理退款、发送通知等。'),
        'doc' => 'order/订单取消后.md',
    ],
    
    // 订单完成
    'Weline_Checkout::order::completed' => [
        'name' => __('订单完成'),
        'description' => __('当订单状态变更为已完成时触发，允许其他模块执行完成后的操作，如增加积分、发送通知等。'),
        'doc' => 'order/订单完成.md',
    ],
    
    // 订单取消
    'Weline_Checkout::order::cancelled' => [
        'name' => __('订单取消'),
        'description' => __('当订单被取消时触发，允许其他模块执行取消后的操作，如恢复库存、处理退款等。'),
        'doc' => 'order/订单取消.md',
    ],
    
    // 订单退款
    'Weline_Checkout::order::refunded' => [
        'name' => __('订单退款'),
        'description' => __('当订单被退款时触发，允许其他模块执行退款后的操作，如更新账户余额、发送通知等。'),
        'doc' => 'order/订单退款.md',
    ],
    
    // ========== 支付事件 ==========
    
    // 支付验证前
    'Weline_Checkout::payment::validate::before' => [
        'name' => __('支付验证前'),
        'description' => __('在验证支付数据之前触发，允许其他模块修改验证数据或执行预处理操作。'),
        'doc' => 'payment/支付验证前.md',
    ],
    
    // 支付验证后
    'Weline_Checkout::payment::validate::after' => [
        'name' => __('支付验证后'),
        'description' => __('在验证支付数据之后触发，允许其他模块根据验证结果执行后续操作。'),
        'doc' => 'payment/支付验证后.md',
    ],
    
    // 支付处理前
    'Weline_Checkout::payment::process::before' => [
        'name' => __('支付处理前'),
        'description' => __('在支付处理之前触发，允许其他模块准备支付参数、选择支付网关、验证支付限额等。'),
        'doc' => 'payment/支付处理前.md',
    ],
    
    // 支付处理后
    'Weline_Checkout::payment::process::after' => [
        'name' => __('支付处理后'),
        'description' => __('在支付处理之后触发，允许其他模块执行实际的支付逻辑，如调用支付网关API、创建支付订单等。'),
        'doc' => 'payment/支付处理后.md',
    ],
    
    // 支付回调前
    'Weline_Checkout::payment::callback::before' => [
        'name' => __('支付回调前'),
        'description' => __('在处理支付回调之前触发，允许其他模块验证回调签名或执行预处理操作。'),
        'doc' => 'payment/支付回调前.md',
    ],
    
    // 支付回调后
    'Weline_Checkout::payment::callback::after' => [
        'name' => __('支付回调后'),
        'description' => __('在处理支付回调之后触发，允许其他模块验证支付结果、更新订单状态、处理退款等。'),
        'doc' => 'payment/支付回调后.md',
    ],
    
    // 支付成功
    'Weline_Checkout::payment::success' => [
        'name' => __('支付成功'),
        'description' => __('当支付成功时触发，允许其他模块执行成功后的操作，如更新订单状态、发送通知、增加积分等。'),
        'doc' => 'payment/支付成功.md',
    ],
    
    // 支付失败
    'Weline_Checkout::payment::failed' => [
        'name' => __('支付失败'),
        'description' => __('当支付失败时触发，允许其他模块执行失败后的操作，如记录日志、发送通知、释放库存等。'),
        'doc' => 'payment/支付失败.md',
    ],
];

