<?php

declare(strict_types=1);

return [
    'name' => 'Weline_StoreMusic',
    'version' => '1.1.60',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Backend' => '*',
        'Weline_Frontend' => '*',
        'Weline_Theme' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Widget' => '*',
    ],
    'optional' => [
        'Weline_MediaManager' => '*',
        'Weline_Consent' => '*',
        'Weline_Websites' => '*',
    ],
    'provides' => [
        'deploy.flat_static.Weline_StoreMusic' => \Weline\StoreMusic\Api\Deploy\FlatStaticRuntimeFilesProvider::class,
    ],
];
