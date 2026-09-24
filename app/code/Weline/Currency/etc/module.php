<?php

return [
    "name" => 'Weline_Currency',
    'version' => '1.0.15',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
        'Weline_Theme' => '*',
        'Weline_Widget' => '*',
    ],
    "provides" => [
        \Weline\Currency\Api\CurrencyCatalogInterface::class => \Weline\Currency\Service\Repository\CurrencyCatalog::class,
        'localization_provider.Weline_Currency' => \Weline\Currency\Api\Localization\LocalizationProvider::class,
        'process_cache_resetter.Weline_Currency' => \Weline\Currency\Api\Runtime\ProcessCacheResetter::class,
    ],
];
