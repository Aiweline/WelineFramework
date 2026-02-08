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
