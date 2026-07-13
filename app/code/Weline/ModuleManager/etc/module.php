<?php

return [
    "name" => 'Weline_ModuleManager',
    "version" => '1.0.2',
    "requires" => [
        'Weline_Admin' => '*',
    ],
    "optional" => [
        'Weline_Ai' => '*',
        'Weline_I18n' => '*',
    ],
    "provides" => [
        \Weline\ModuleManager\Api\ModuleCatalogInterface::class => \Weline\ModuleManager\Service\ModuleCatalog::class,
        \Weline\Framework\Module\ModuleIdentityProviderInterface::class => \Weline\ModuleManager\Api\ModuleIdentityProvider::class,
    ],
];
