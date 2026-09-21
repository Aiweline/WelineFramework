<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Framework 模块扩展规约
 * 
 * 本文件定义了 Weline_Framework 模块提供的扩展点，其他模块可以通过这些扩展点来扩展功能
 */
return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        // 缓存驱动扩展点
        'Cache' => [
            'path' => 'extends/module/Weline_Framework/Cache',
            'type' => ['module'],
            'description' => '缓存驱动扩展点，用于扩展或替换缓存驱动实现',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Cache\CacheDriverInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Cache/{DriverName}.php',
                    'description' => '缓存驱动实现类位置',
                    'example' => 'app/code/Weline/Server/extends/module/Weline_Framework/Cache/WlsMemoryCache.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Cache\CacheDriverInterface',
                    'description' => '缓存驱动必须实现的接口',
                    'required_methods' => [
                        'get' => '获取缓存值',
                        'set' => '设置缓存值',
                        'exists' => '检查缓存是否存在',
                        'delete' => '删除缓存',
                        'flush' => '清空缓存',
                        'clear' => '清理缓存',
                    ],
                ],
                'base_class' => [
                    'class' => 'Weline\Framework\Cache\Driver\File',
                    'description' => '可选：继承 File 驱动以复用文件缓存逻辑',
                ],
            ],
        ],
        // 统一查询器扩展点
        'Query' => [
            'path' => 'extends/module/Weline_Framework/Query',
            'type' => ['module'],
            'description' => '统一查询器扩展点，各模块实现 QueryProviderInterface 注册查询能力（含执行与使用说明）',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Service\Query\Provider\QueryProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Query/{ProviderName}QueryProvider.php',
                    'description' => '查询器实现类位置',
                    'example' => 'app/code/Weline/Widget/extends/module/Weline_Framework/Query/WidgetQueryProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Service\Query\Provider\QueryProviderInterface',
                    'description' => '查询器必须实现的接口',
                    'required_methods' => [
                        'getProviderName' => '返回提供者标识（如 widget），用于路由',
                        'execute' => '执行查询操作',
                        'getDescriptor' => '返回使用说明描述（provider、operations、params 等）',
                    ],
                ],
            ],
        ],
        // Schema 扩展点（声明式表结构提供者，由 SchemaDiffStage 统一执行）
        'Schema' => [
            'path' => 'extends/module/Weline_Framework/Schema',
            'type' => ['module'],
            'description' => 'Schema 提供者扩展点：实现 SchemaProviderInterface，或专用 ShardSchemaFamilyProviderInterface（分片族）。均由 SchemaDiffStage 合入声明式 diff；单站 provision 走 ShardSchemaProvisioner',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Database\Schema\SchemaProviderInterface',
            'details' => [
                'interface' => [
                    'interface' => 'Weline\Framework\Database\Schema\SchemaProviderInterface',
                    'description' => '必须实现 getTableSchemas(): array，返回 TableSchema[]；分片族另实现 getFamilyCode/getRegisteredShardKeys/getTableSchemasForShard',
                    'required_methods' => [
                        'getTableSchemas' => '返回本提供者声明的表结构列表（分片族须展开全部 registered shard）',
                    ],
                ],
                'shard_family' => [
                    'interface' => 'Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderInterface',
                    'description' => '可选专用接口；family code 唯一；DDL 由 ShardSchemaProvisioner 执行，业务 DML 走 DatabaseTransactionRunner',
                ],
            ],
        ],
        // CSP 应用默认扩展点（模块贡献域名，汇总为不可覆盖的系统默认）
        'Security/Csp' => [
            'path' => 'extends/module/Weline_Framework/Security/Csp',
            'type' => ['module'],
            'description' => 'CSP 应用默认扩展点：模块贡献 script/frame/connect 等 source，框架收集后并入系统默认并强制放行；SystemConfig Scope 不可删除这些 source',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Http\Security\CspSourceContributionProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Security/Csp/{ProviderName}.php',
                    'description' => 'CSP 应用默认贡献类位置',
                    'example' => 'app/code/WeShop/Payment/extends/module/Weline_Framework/Security/Csp/StripeCsp.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Http\Security\CspSourceContributionProviderInterface',
                    'description' => '必须实现 contribution(): CspSourceContribution，返回 directive=>sources',
                    'required_methods' => [
                        'contribution' => '返回本模块需要放行的 CSP 指令与 source 列表',
                    ],
                ],
            ],
        ],
        // Changed：Capability / Type（Enricher+Recipe）自动收集
        'Changed/Capability' => [
            'path' => 'extends/module/Weline_Framework/Changed/Capability',
            'type' => ['module'],
            'description' => '资源变更失效 Capability（FPC/CDN/cache_ops 等 Effect 执行器）',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Event\Changed\ChangedCapabilityInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Changed/Capability/{Name}.php',
                    'description' => 'Changed Capability 实现',
                    'example' => 'app/code/Weline/Framework/Extends/module/Weline_Framework/Changed/Capability/FpcCapability.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Event\Changed\ChangedCapabilityInterface',
                    'description' => '执行 InvalidationEffect',
                    'required_methods' => [
                        'code' => 'Capability 代码',
                        'supportedEffects' => '支持的 Effect 名列表',
                        'execute' => '执行 Effect',
                    ],
                ],
            ],
        ],
        'Changed/Type' => [
            'path' => 'extends/module/Weline_Framework/Changed/Type',
            'type' => ['module'],
            'description' => '资源变更类型合同（Enricher + Recipe）',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Event\Changed\ChangedTypeInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Changed/Type/{Name}.php',
                    'description' => 'ChangedType 实现',
                    'example' => 'app/code/Weline/Product/extends/module/Weline_Framework/Changed/Type/ProductSearchProjectionChangedType.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Event\Changed\ChangedTypeInterface',
                    'description' => '类型合同 + enrich + recipe',
                    'required_methods' => [
                        'code' => '资源 type 代码',
                        'enrich' => '信封 → impact 材料',
                        'recipe' => 'impact → Effects',
                    ],
                ],
            ],
        ],
        // Extra 类型注册（如 fpc）
        'Extra/Type' => [
            'path' => 'extends/module/Weline_Framework/Extra/Type',
            'type' => ['module'],
            'description' => '控制器 @Extra type 提供者（读路径策略）',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Controller\Extra\ExtraTypeProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Extra/Type/{Name}.php',
                    'description' => 'Extra type 提供者',
                    'example' => 'app/code/Weline/Framework/Extends/module/Weline_Framework/Extra/Type/FpcExtraType.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Controller\Extra\ExtraTypeProviderInterface',
                    'description' => '注册并规范化 Extra type',
                    'required_methods' => [
                        'type' => 'type 名（如 fpc）',
                        'normalize' => '校验并规范化声明字段',
                    ],
                ],
            ],
        ],
        // FPC 旁路规则（Theme 等声明编辑器/预览参数）
        'Fpc/Bypass' => [
            'path' => 'extends/module/Weline_Framework/Fpc/Bypass',
            'type' => ['module'],
            'description' => 'FPC bypass 规则提供者（请求身份/显式参数）',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Fpc/Bypass/{Name}.php',
                    'description' => 'Bypass 规则提供者',
                    'example' => 'app/code/Weline/Theme/extends/module/Weline_Framework/Fpc/Bypass/ThemeEditorFpcBypassProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface',
                    'description' => '返回 bypass 规则列表',
                    'required_methods' => [
                        'rules' => '规则列表',
                    ],
                ],
            ],
        ],
        // FPC 存储适配器（WLS 默认）
        'Fpc/Store' => [
            'path' => 'extends/module/Weline_Framework/Fpc/Store',
            'type' => ['module'],
            'description' => 'FPC 存储适配器（process/shared 等）',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Http\Fpc\FpcStoreAdapterInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Fpc/Store/{Name}.php',
                    'description' => 'FPC Store 适配器',
                    'example' => 'app/code/Weline/Server/extends/module/Weline_Framework/Fpc/Store/WlsFpcStoreAdapter.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Http\Fpc\FpcStoreAdapterInterface',
                    'description' => 'FPC 仓读写失效',
                    'required_methods' => [
                        'code' => '适配器代码',
                        'purgeUrls' => '按 URL 删',
                        'purgeAll' => '整池清',
                        'clearProcessCache' => '清进程仓',
                    ],
                ],
            ],
        ],
        // Session 驱动扩展点
        'Session' => [
            'path' => 'extends/module/Weline_Framework/Session',
            'type' => ['module'],
            'description' => 'Session 驱动扩展点，用于扩展或替换 Session 驱动实现',
            'required' => false,
            'multiple' => true,
            'interface' => 'Weline\Framework\Session\Driver\SessionDriverHandlerInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Framework/Session/{DriverName}.php',
                    'description' => 'Session 驱动实现类位置',
                    'example' => 'app/code/Weline/Server/extends/module/Weline_Framework/Session/WlsMemorySession.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Framework\Session\Driver\SessionDriverHandlerInterface',
                    'description' => 'Session 驱动必须实现的接口',
                    'required_methods' => [
                        'set' => '设置 Session 值',
                        'get' => '获取 Session 值',
                        'delete' => '删除 Session 值',
                        'getSessionId' => '获取 Session ID',
                        'destroy' => '销毁 Session',
                        'read' => '读取 Session 数据',
                        'write' => '写入 Session 数据',
                    ],
                ],
                'base_class' => [
                    'class' => 'Weline\Framework\Session\Driver\File',
                    'description' => '可选：继承 File 驱动以复用文件 Session 逻辑',
                ],
            ],
        ],
    ],
];
