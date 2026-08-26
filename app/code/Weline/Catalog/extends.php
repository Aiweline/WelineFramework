<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'CatalogSpace' => [
            'path' => 'extends/module/Weline_Catalog/Space',
            'interface' => \Weline\Catalog\Api\CatalogSpaceProviderInterface::class,
            'description' => 'Catalog space provider registered into the universal Catalog hub.',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
