<?php

return [
    "name" => 'Weline_Index',
    "version" => '1.0.2',
    "requires" => [
    ],
    "optional" => [
        'Weline_Seo' => '*',
    ],
    "provides" => [
        'view_warmup_contribution.Weline_Index'
            => \Weline\Index\Api\View\ViewWarmupContributionProvider::class,
    ],
];
