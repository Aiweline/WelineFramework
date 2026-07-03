<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'TargetType' => [
            'path' => 'extends/module/Weline_Theme/TargetType',
            'interface' => 'Weline\Theme\Api\TargetTypeProviderInterface',
            'description' => 'Theme target type provider extension point for modules that bind Theme layouts, virtual layouts, preview, render and Meta identify data to concrete business targets.',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Theme/TargetType/{TargetTypeProvider}.php',
                    'example' => 'app/code/Weline/Cms/extends/module/Weline_Theme/TargetType/CmsPageTargetTypeProvider.php',
                ],
            ],
        ],
    ],
];
