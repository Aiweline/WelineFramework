<?php

return [
    "name" => 'Weline_Currency',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
    ],
    "provides" => [
        \Weline\Currency\Api\CurrencyCatalogInterface::class => \Weline\Currency\Service\Repository\CurrencyCatalog::class,
        'localization_provider.Weline_Currency' => \Weline\Currency\Api\Localization\LocalizationProvider::class,
    ],
];
