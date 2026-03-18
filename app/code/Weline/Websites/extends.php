<?php
declare(strict_types=1);

use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Websites\Extends\NotificationTopicProvider;

/**
 * Weline_Websites 模块扩展规约
 *
 * 本文件定义了 Weline_Websites 模块提供的扩展点：
 * - Registrar: 域名商适配器扩展点，用于接入第三方域名注册商 API
 */
return [
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        // 域名商适配器扩展点
        'Registrar' => [
            'path' => 'extends/module/Weline_Websites/Registrar',
            'type' => ['module'],
            'description' => __('域名商适配器扩展点，用于接入第三方域名注册商 API（如 GoDaddy、Namecheap 等）。适配器实现域名可用性检查、购买和管理功能。'),
            'required' => true,
            'interface' => 'Weline\\Websites\\Api\\DomainRegistrarInterface',
            'multiple' => true,
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Websites/Registrar/{RegistrarName}.php',
                    'description' => __('域名商适配器实现类，在模块的 extends/module/Weline_Websites/Registrar/ 目录下创建PHP文件'),
                    'example' => 'extends/module/Weline_Websites/Registrar/GoDaddy.php',
                ],
                'implementation' => [
                    'interface' => 'Weline\\Websites\\Api\\DomainRegistrarInterface',
                    'description' => __('必须实现 DomainRegistrarInterface（含 listZoneDnsRecordsForAccount，见 DnsCdnZoneRecordsProviderInterface）'),
                    'required_methods' => [
                        'getRegistrarCode' => __('返回适配器唯一标识'),
                        'getRegistrarName' => __('返回适配器显示名称'),
                        'getDescription' => __('返回适配器描述'),
                        'getVersion' => __('返回适配器版本'),
                        'getConfigFields' => __('返回适配器所需的配置字段定义'),
                        'testConnection' => __('测试 API 连通性'),
                        'checkAvailability' => __('检查域名可用性'),
                        'batchCheckAvailability' => __('批量检查域名可用性'),
                        'purchaseDomain' => __('购买域名'),
                        'getDomainList' => __('获取已有域名列表'),
                        'getDomainDetail' => __('获取域名详情'),
                    ],
                ],
                'use_case' => [
                    'description' => __('适用于接入更多域名注册商 API，第三方模块可以通过 extends 机制扩展新的域名商'),
                    'example' => __('GoDaddy、Namecheap、Dynadot 等域名商适配器可通过此扩展点接入'),
                ],
            ],
        ],
    ],
];
