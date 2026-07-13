<?php

return [
    "name" => 'Weline_Marketing',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Marketing\Api\Rule\ActionCatalogInterface::class => \Weline\Marketing\Service\ActionCatalog::class,
    ],
];
