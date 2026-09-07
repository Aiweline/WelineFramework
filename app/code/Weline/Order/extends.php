<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Order 模块扩展规约
 *
 * TrackingProvider 为正式跟踪扩展点（对齐万能支付 PaymentProvider）。
 * 下方 legacy 条目保留兼容说明，新对接请走 TrackingProvider。
 */
return [
    'type' => 'module',
    'documentation' => 'doc/extends.md',
    'extends' => [
        'CommerceOrderType' => [
            'path' => 'extends/module/Weline_Order/CommerceOrderType',
            'interface' => 'Weline\\Order\\Api\\CommerceOrderTypeInterface',
            'description' => '订单售卖类型 SPI（toc 内置；tob 等由业务模块扩展）。',
            'required' => false,
            'multiple' => true,
        ],
        'TrackingProvider' => [
            'path' => 'extends/module/Weline_Order/TrackingProvider',
            'interface' => 'Weline\Order\Interface\TrackingProviderInterface',
            'description' => '订单物流跟踪 Provider 扩展点。第三方物流模块实现此接口以接入统一跟踪壳（图标、名称、流程、查询与反馈验签/解析）。',
            'required' => true,
            'multiple' => true,
        ],
        'PaymentMethods' => [
            'path' => 'Service/PaymentMethod/{PaymentMethodName}.php',
            'type' => ['module'],
            'description' => '【legacy】历史支付方式扩展点；新支付请对接 Weline_Payment ProviderInterface。',
            'required' => false,
            'multiple' => true,
        ],
        'ShippingMethods' => [
            'path' => 'Service/ShippingMethod/{ShippingMethodName}.php',
            'type' => ['module'],
            'description' => '【legacy】历史配送方式扩展点；正式物流跟踪请实现 TrackingProvider。',
            'required' => false,
            'multiple' => true,
        ],
        'OrderStatuses' => [
            'path' => 'Service/OrderStatus/{StatusName}.php',
            'type' => ['module'],
            'description' => '订单状态扩展点，允许其他模块注册自定义订单状态。',
            'required' => false,
            'multiple' => true,
        ],
        'OrderCalculators' => [
            'path' => 'Service/Calculator/{CalculatorName}.php',
            'type' => ['module'],
            'description' => '订单计算器扩展点，允许其他模块注册自定义订单计算逻辑（如税费、折扣等）。',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
