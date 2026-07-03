<?php
declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'TrashProvider' => [
            'path' => 'extends/module/Weline_Trash/TrashProvider',
            'interface' => 'Weline\Trash\Api\TrashProviderInterface',
            'description' => '业务模块通过静态 TrashProvider 注册回收站删除、恢复和永久清理能力。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Trash/TrashProvider/{ProviderName}.php',
                    'example' => 'app/code/Weline/Cms/extends/module/Weline_Trash/TrashProvider/CmsPageTrashProvider.php',
                ],
                'interface' => [
                    'interface' => 'Weline\Trash\Api\TrashProviderInterface',
                    'required_methods' => [
                        'code' => '返回全局唯一业务 code，例如 weline_cms.page',
                        'label' => '返回后台显示名称',
                        'trash' => '执行业务删除并返回回收站快照数据',
                        'restore' => '根据回收站记录执行业务恢复',
                        'purge' => '根据回收站记录执行永久清理或明确拒绝',
                    ],
                ],
            ],
        ],
    ],
];
