<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Payment 模块扩展规约
 * 
 * 本文件定义了 Weline_Payment 模块提供的扩展点，其他模块可以通过这些扩展点来扩展支付功能
 */
return [
    'type' => 'module', // module 或 theme
    'documentation' => 'doc/extends.md', // 文档文件路径（相对于模块根目录）
    'extends' => [
        'PaymentProvider' => [
            'path' => 'extends/module/Weline_Payment/PaymentProvider',
            'interface' => 'Weline\Payment\Interface\ProviderInterface',
            'description' => '支付提供商扩展点，用于扩展支付功能。其他支付供应商可以开发支付模块，实现此接口来接入支付系统。',
            'required' => true, // 是否必须实现接口
            'multiple' => true  // 是否允许多个实现
        ],
        'PayableResolver' => [
            'path' => 'extends/module/Weline_Payment/PayableResolver',
            'interface' => 'Weline\Payment\Interface\PayableResolverInterface',
            'description' => '可支付对象解析扩展点，用于订单、商城、应用市场、A2A 等业务对象接入统一支付内核。',
            'required' => true,
            'multiple' => true
        ]
    ]
];

