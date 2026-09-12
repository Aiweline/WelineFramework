<?php

declare(strict_types=1);

return [
    'name' => 'Weline_HelpPay',
    'version' => '1.0.0',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Frontend' => '*',
        'Weline_Theme' => '*',
        'Weline_Widget' => '*',
        'Weline_Payment' => '>=1.8.0',
        'Weline_Cart' => '*',
        'Weline_Checkout' => '*',
        'Weline_SystemConfig' => '*',
    ],
    'optional' => [
        'Weline_Faq' => '*',
        'Weline_Order' => '*',
        'Weline_Shipping' => '*',
    ],
    'provides' => [],
];
