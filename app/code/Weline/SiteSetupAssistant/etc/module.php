<?php

declare(strict_types=1);

return [
    'name' => 'Weline_SiteSetupAssistant',
    'version' => '0.3.4',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Dashboard' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
        'Weline_Widget' => '*',
    ],
    'optional' => [
        'Weline_Smtp' => '*',
        'Weline_Captcha' => '*',
        'Weline_Customer' => '*',
        'Weline_Social' => '*',
        'Weline_Payment' => '*',
        'Weline_Seo' => '*',
        'Weline_Shipping' => '*',
        'Weline_Currency' => '*',
        'Weline_Visitor' => '*',
        'Weline_CustomerService' => '*',
        'Weline_Cdn' => '*',
    ],
    'provides' => [],
];
