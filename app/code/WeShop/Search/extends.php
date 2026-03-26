<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'Document' => [
            'path' => 'extends/module/WeShop_Search/Document',
            'type' => ['module'],
            'description' => 'Search document provider extension point',
            'required' => true,
            'multiple' => true,
            'interface' => 'WeShop\\Search\\Api\\SearchDocumentProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/WeShop_Search/Document/{ProviderName}.php',
                    'description' => 'Search document provider implementation',
                    'example' => 'app/code/WeShop/Product/Extends/module/WeShop_Search/Document/ProductDocumentProvider.php',
                ],
            ],
        ],
        'DocumentExtender' => [
            'path' => 'extends/module/WeShop_Search/DocumentExtender',
            'type' => ['module'],
            'description' => 'Search document extender extension point',
            'required' => false,
            'multiple' => true,
            'interface' => 'WeShop\\Search\\Api\\SearchDocumentExtenderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/WeShop_Search/DocumentExtender/{ExtenderName}.php',
                    'description' => 'Search document extender implementation',
                    'example' => 'app/code/WeShop/Product/Extends/module/WeShop_Search/DocumentExtender/ProductEavSearchDocumentExtender.php',
                ],
            ],
        ],
    ],
];
