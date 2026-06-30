<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'Provider' => [
            'path' => 'extends/module/Weline_FakeData/Provider',
            'type' => ['module'],
            'description' => 'Fake data provider extension point. Providers are discovered by fake-data:import and are never executed during setup.',
            'required' => true,
            'multiple' => true,
            'interface' => 'Weline\FakeData\Api\FakeDataProviderInterface',
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_FakeData/Provider/{ProviderName}Provider.php',
                    'description' => 'Fake data provider implementation class.',
                    'example' => 'app/code/WeShop/Product/extends/module/Weline_FakeData/Provider/ProductProvider.php',
                ],
            ],
        ],
    ],
];

