<?php

declare(strict_types=1);

/**
 * Weline_Customer module extension points.
 */
return [
    'type' => 'module',
    'documentation' => 'doc/extends.md',
    'extends' => [
        'SocialLoginProvider' => [
            'path' => 'extends/module/Weline_Customer/SocialLoginProvider',
            'interface' => 'Weline\Customer\Interface\SocialLoginProviderInterface',
            'description' => '顾客前台社媒登录提供商扩展点。其他模块实现此接口并放到约定目录即可接入统一 OAuth start/callback、绑定表与登录 Logo。禁止用 Hook 注册 OAuth 提供商。',
            'required' => true,
            'multiple' => true,
        ],
        'AccountMenuSignalProvider' => [
            'path' => 'extends/module/Weline_Customer/AccountMenuSignalProvider',
            'type' => ['module'],
            'description' => '个人中心/顶栏菜单未读信号 SPI：按稳定 code 汇总条数，由 JS 绘制角标，禁止 SSR 登录未读数。',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Customer\Api\AccountMenuSignalProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Customer/AccountMenuSignalProvider/{Name}Provider.php',
                    'description' => '实现 AccountMenuSignalProviderInterface；code 与账户 section 对齐（如 product.quotes）。',
                    'example' => 'app/code/Weline/Product/extends/module/Weline_Customer/AccountMenuSignalProvider/ProductQuoteMenuSignalProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Customer\Api\AccountMenuSignalProviderInterface',
                    'required_methods' => [
                        'code' => '稳定菜单信号编码',
                        'count' => '未读/待处理条数',
                    ],
                ],
            ],
        ],
    ],
];
