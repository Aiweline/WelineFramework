<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Vendor',
    'version' => '1.5.0',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
        'Weline_Acl' => '*',
        'Weline_Product' => '*',
    ],
    'optional' => [
        'Weline_Order' => '*',
        'Weline_Payment' => '*',
    ],
    'provides' => [],
];
