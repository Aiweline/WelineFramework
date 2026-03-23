<?php

declare(strict_types=1);

use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Websites\Extends\NotificationTopicProvider;

/**
 * Weline_Websites module extension contracts.
 */
return [
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'Registrar' => [
            'path' => 'extends/module/Weline_Websites/Registrar',
            'type' => ['module'],
            'description' => __('域名注册商适配器扩展点，用于接入第三方域名注册商 API。'),
            'required' => true,
            'interface' => 'Weline\\Websites\\Api\\DomainRegistrarInterface',
            'multiple' => true,
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Websites/Registrar/{RegistrarName}.php',
                    'description' => __('域名注册商适配器实现类，在模块的 extends/module/Weline_Websites/Registrar/ 目录下创建 PHP 文件。'),
                    'example' => 'extends/module/Weline_Websites/Registrar/GoDaddy.php',
                ],
                'implementation' => [
                    'interface' => 'Weline\\Websites\\Api\\DomainRegistrarInterface',
                    'description' => __('实现注册商元数据、可用性检查、购买与管理能力。'),
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
                    'description' => __('适用于接入更多域名注册商 API。'),
                    'example' => __('GoDaddy、Namecheap、Dynadot 等域名商适配器可通过此扩展点接入。'),
                ],
            ],
        ],
        'AiSiteBuilderProvider' => [
            'path' => 'extends/module/Weline_Websites/AiSiteBuilderProvider',
            'type' => ['module'],
            'description' => __('AI 建站流程提供者扩展点。provider_code 绑定完整建站流程，而不是零散工具列表。'),
            'required' => false,
            'interface' => 'Weline\\Websites\\Api\\AiSiteBuilderProviderInterface',
            'multiple' => true,
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Websites/AiSiteBuilderProvider/{ProviderName}.php',
                    'description' => __('AI 建站 provider 实现类，在模块的 extends/module/Weline_Websites/AiSiteBuilderProvider/ 目录下创建 PHP 文件。'),
                    'example' => 'extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php',
                ],
                'implementation' => [
                    'interface' => 'Weline\\Websites\\Api\\AiSiteBuilderProviderInterface',
                    'description' => __('实现完整 AI 建站流程 provider 的元数据契约，具体流程能力由 provider 自身承接。'),
                    'required_methods' => [
                        'getCode' => __('返回 provider 唯一编码'),
                        'getName' => __('返回 provider 名称'),
                        'getDescription' => __('返回 provider 描述'),
                        'isEnabled' => __('返回 provider 是否启用'),
                        'getSortOrder' => __('返回 provider 排序值'),
                    ],
                    'optional_interfaces' => [
                        'Weline\\Websites\\Api\\AiSiteBuilderWorkbenchProviderInterface' => __('可选实现，用于声明工作区 scope 初始化、handoff 和 tools。'),
                    ],
                ],
                'use_case' => [
                    'description' => __('用于向 Websites AI 建站工作台接入不同流程提供者，例如默认流程、PageBuilder 流程或未来其他模块流程。'),
                    'example' => __('PageBuilder 可通过此扩展点把自己的建站流程挂接到 Websites 工作台入口。'),
                ],
            ],
        ],
        'WebsiteThemeSource' => [
            'path' => 'extends/module/Weline_Websites/WebsiteThemeSource',
            'type' => ['module'],
            'description' => __('网站主题来源扩展点，由主题提供模块对外暴露主题候选能力。'),
            'required' => false,
            'interface' => 'Weline\\Websites\\Api\\WebsiteThemeSourceInterface',
            'multiple' => true,
            'details' => [
                'module_mode' => [
                    'path' => 'extends/module/Weline_Websites/WebsiteThemeSource/{SourceName}.php',
                    'description' => __('主题来源实现类，在模块的 extends/module/Weline_Websites/WebsiteThemeSource/ 目录下创建 PHP 文件。'),
                    'example' => 'extends/module/Weline_Websites/WebsiteThemeSource/WelineThemeSource.php',
                ],
                'implementation' => [
                    'interface' => 'Weline\\Websites\\Api\\WebsiteThemeSourceInterface',
                    'description' => __('实现主题来源元数据与主题候选列表读取能力，不向 Websites 核心暴露模块私有字段。'),
                    'required_methods' => [
                        'getCode' => __('返回主题来源唯一编码'),
                        'getName' => __('返回主题来源名称'),
                        'getDescription' => __('返回主题来源描述'),
                        'isEnabled' => __('返回主题来源是否启用'),
                        'getSortOrder' => __('返回主题来源排序值'),
                        'listThemes' => __('返回当前来源提供的主题候选列表'),
                    ],
                ],
                'use_case' => [
                    'description' => __('用于让 Theme、PageBuilder 或未来其他模块以独立来源方式向 Websites 工作台提供主题选择能力。'),
                    'example' => __('Weline_Theme 可通过此扩展点输出 layouts 派生的主题候选。'),
                ],
            ],
        ],
    ],
];
