<?php

declare(strict_types=1);

/**
 * Weline_Admin module extension points.
 */
return [
    'type' => 'module',
    'documentation' => 'doc/extends.md',
    'extends' => [
        'BackendMenuSignalProvider' => [
            'path' => 'extends/module/Weline_Admin/BackendMenuSignalProvider',
            'type' => ['module'],
            'description' => '后台侧栏菜单未读信号 SPI：按菜单 source_id（Vendor_Module::name）汇总条数与可选深链 items，由 JS 绘制角标/改写叶 href，禁止 SSR 未读数字。',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Admin\Api\BackendMenuSignalProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Admin/BackendMenuSignalProvider/{Name}Provider.php',
                    'description' => '实现 BackendMenuSignalProviderInterface；可选再实现 BackendMenuSignalItemsProviderInterface 提供消息级深链；code() 必须等于菜单 source_id（如 Weline_CustomerService::console）。',
                    'example' => 'app/code/Weline/CustomerService/extends/module/Weline_Admin/BackendMenuSignalProvider/CustomerServiceConsoleMenuSignalProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Admin\Api\BackendMenuSignalProviderInterface',
                    'required_methods' => [
                        'code' => '菜单 source_id（ACL source 形）',
                        'count' => '未读/待处理条数（按 backend user id）',
                    ],
                    'optional_interfaces' => [
                        'Weline\\Admin\\Api\\BackendMenuSignalItemsProviderInterface' => 'items() 返回归属模块自产的深链列表（id/title/href/count）；Admin JS 禁止 invent URL',
                    ],
                ],
            ],
        ],
    ],
];
