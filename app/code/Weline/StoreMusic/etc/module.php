<?php

declare(strict_types=1);

return [
    'name' => 'Weline_StoreMusic',
    'version' => '1.1.9',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Backend' => '*',
        'Weline_Frontend' => '*',
        'Weline_Theme' => '*',
        'Weline_SystemConfig' => '*',
    ],
    'optional' => [
        'Weline_MediaManager' => '*',
        'Weline_Consent' => '*',
        'Weline_Websites' => '*',
    ],
];
