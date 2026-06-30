<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/extends.md',
    'extends' => [
        'PlatformProvider' => [
            'path' => 'extends/module/Weline_Social',
            'type' => ['module'],
            'description' => '融媒体平台 Provider 注册入口。扩展模块可在 platforms.php 中一次返回多个 Provider class。',
            'required' => true,
            'multiple' => true,
            'interface' => 'Weline\\Social\\Interface\\SocialPlatformProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Social/platforms.php',
                    'description' => '返回 SocialPlatformProviderInterface 实现类列表。',
                    'example' => "return [Vendor\\Module\\Platform\\CustomProvider::class];",
                ],
            ],
        ],
    ],
];

