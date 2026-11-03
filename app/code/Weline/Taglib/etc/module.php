<?php

return [
    "name" => 'Weline_Taglib',
    "version" => '1.1.12',
    "requires" => [
        'Weline_Framework' => '*',
        'Weline_ModuleManager' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'worker_preload_provider.Weline_Taglib_Catalogue'
            => \Weline\Taglib\Service\Preload\TaglibCataloguePreloadProvider::class,
    ],
];
