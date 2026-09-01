<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'CatalogSpace' => [
            'path' => 'extends/module/Weline_Catalog/Space',
            'interface' => \Weline\Catalog\Api\CatalogSpaceProviderInterface::class,
            'description' => 'Blog catalog space provider for Weline_Catalog hub.',
            'required' => false,
            'multiple' => false,
        ],
    ],
];
