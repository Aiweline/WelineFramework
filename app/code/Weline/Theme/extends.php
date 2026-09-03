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
        'BrandBasicsIdentity' => [
            'path' => 'extends/module/Weline_Theme/BrandBasicsIdentity',
            'interface' => 'Weline\Theme\Api\BrandBasicsIdentityProviderInterface',
            'description' => 'Theme Editor brand-basics identity slot. Modules attach real Website/Store/Channel name & description; register via provides theme.brand_basics_identity.* or this extends path.',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Theme/BrandBasicsIdentity/{Provider}.php',
                    'example' => 'app/code/Weline/Websites/Service/ThemeBrandBasicsIdentityProvider.php',
                ],
            ],
        ],
    ],
];
