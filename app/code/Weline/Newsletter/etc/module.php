<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Newsletter',
    'version' => '1.0.13',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Frontend' => '*',
        'Weline_Theme' => '*',
        'Weline_Widget' => '*',
        'Weline_Smtp' => '*',
        'Weline_Marketing' => '*',
        'Weline_Websites' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Acl' => '*',
        'Weline_Backend' => '*',
    ],
    'provides' => [
        \Weline\Newsletter\Api\NewsletterCheckoutCouponAutoApplyInterface::class
            => \Weline\Newsletter\Service\CheckoutAutoApplyService::class,
    ],
];
