<?php

return [
    "name" => 'Weline_Order',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_Payment' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'view_warmup_contribution.Weline_Order' => \Weline\Order\Api\View\ViewWarmupContributionProvider::class,
    ],
];
