<?php

return [
    "name" => 'Weline_AppStore',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Eav' => '*',
        'Weline_Database' => '*',
    ],
    "provides" => [
        \Weline\AppStore\Api\AppStorePlatformUrlResolverInterface::class => \Weline\AppStore\Service\AppStorePlatformUrlResolver::class,
    ],
];
