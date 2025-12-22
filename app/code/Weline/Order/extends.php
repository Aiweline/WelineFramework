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
 * 本文件定义了 Weline_Order 模块提供的扩展点，其他模块可以通过这些扩展点来扩展订单管理功能
 */
return [
    'type' => 'module',
    'documentation' => 'doc/扩展点说明.md',
    'extends' => [
        'PaymentMethods' => [
            'path' => 'Service/PaymentMethod/{PaymentMethodName}.php',
            'type' => ['module'],
            'description' => '支付方式扩展点，允许其他模块注册自定义支付方式。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'Service/PaymentMethod/{PaymentMethodName}.php',
                    'description' => '支付方式实现类位置，{PaymentMethodName} 为支付方式名称',
                    'example' => 'app/code/Weline/Order/Service/PaymentMethod/Alipay.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Order\Service\PaymentMethod\PaymentMethodInterface',
                    'description' => '支付方式必须实现 PaymentMethodInterface 接口',
                    'required_methods' => [
                        'process' => '处理支付',
                        'refund' => '处理退款',
                        'getConfig' => '获取配置',
                    ],
                ],
            ],
        ],
        
        'ShippingMethods' => [
            'path' => 'Service/ShippingMethod/{ShippingMethodName}.php',
            'type' => ['module'],
            'description' => '配送方式扩展点，允许其他模块注册自定义配送方式。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'Service/ShippingMethod/{ShippingMethodName}.php',
                    'description' => '配送方式实现类位置，{ShippingMethodName} 为配送方式名称',
                    'example' => 'app/code/Weline/Order/Service/ShippingMethod/Express.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Order\Service\ShippingMethod\ShippingMethodInterface',
                    'description' => '配送方式必须实现 ShippingMethodInterface 接口',
                    'required_methods' => [
                        'calculate' => '计算运费',
                        'track' => '物流跟踪',
                        'getConfig' => '获取配置',
                    ],
                ],
            ],
        ],
        
        'OrderStatuses' => [
            'path' => 'Service/OrderStatus/{StatusName}.php',
            'type' => ['module'],
            'description' => '订单状态扩展点，允许其他模块注册自定义订单状态。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'Service/OrderStatus/{StatusName}.php',
                    'description' => '订单状态实现类位置，{StatusName} 为状态名称',
                    'example' => 'app/code/Weline/Order/Service/OrderStatus/CustomStatus.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Order\Service\OrderStatus\OrderStatusInterface',
                    'description' => '订单状态必须实现 OrderStatusInterface 接口',
                    'required_methods' => [
                        'canTransition' => '检查是否可以转换',
                        'onEnter' => '进入状态时的处理',
                        'onExit' => '退出状态时的处理',
                    ],
                ],
            ],
        ],
        
        'OrderCalculators' => [
            'path' => 'Service/Calculator/{CalculatorName}.php',
            'type' => ['module'],
            'description' => '订单计算器扩展点，允许其他模块注册自定义订单计算逻辑（如税费、折扣等）。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'Service/Calculator/{CalculatorName}.php',
                    'description' => '计算器实现类位置，{CalculatorName} 为计算器名称',
                    'example' => 'app/code/Weline/Order/Service/Calculator/TaxCalculator.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Order\Service\Calculator\CalculatorInterface',
                    'description' => '计算器必须实现 CalculatorInterface 接口',
                    'required_methods' => [
                        'calculate' => '执行计算',
                        'getType' => '获取计算器类型',
                    ],
                ],
            ],
        ],
    ],
];

