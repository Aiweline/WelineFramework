<?php

return [
    "name" => 'Weline_ModuleRouter',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\ModuleRouter\Api\RouterRulesReaderInterface::class => \Weline\ModuleRouter\Config\ModuleRouterReader::class,
        'process_cache_resetter.Weline_ModuleRouter' => \Weline\ModuleRouter\Api\Runtime\ProcessCacheResetter::class,
    ],
];
