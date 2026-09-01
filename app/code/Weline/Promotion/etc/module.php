<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Promotion',
    'version' => '1.1.5',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Backend' => '*',
        'Weline_Theme' => '*',
        'Weline_I18n' => '*',
    ],
    'optional' => [
        'Weline_Marketing' => '*',
        'Weline_Product' => '*',
        'Weline_Websites' => '*',
        'Weline_Report' => '*',
        'Weline_CustomerService' => '*',
    ],
];
