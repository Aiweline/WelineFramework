<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'TaxRateRemoteProvider' => [
            'path' => 'extends/module/Weline_Tax/TaxRateRemoteProvider',
            'interface' => 'Weline\\Tax\\Api\\TaxRateRemoteProviderInterface',
            'description' => '税率远程/静态候选源 SPI；免费源并集 + 可选专业源覆盖。',
            'required' => false,
            'multiple' => true,
            'details' => [
                'file_location' => [
                    'path' => 'extends/module/Weline_Tax/TaxRateRemoteProvider/{ProviderName}.php',
                ],
            ],
        ],
    ],
];
