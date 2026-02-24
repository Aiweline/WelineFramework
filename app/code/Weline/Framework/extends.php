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
