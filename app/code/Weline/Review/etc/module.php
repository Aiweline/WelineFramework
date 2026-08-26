<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Review',
    'version' => '1.0.0',
    'requires' => [
        'Weline_Framework' => '*',
    ],
    'optional' => [
        'Weline_Product' => '*',
        'Weline_Customer' => '*',
        'Weline_Msg' => '*',
        'Weline_Cron' => '*',
        'Weline_Ai' => '*',
        'Weline_Seo' => '*',
        'Weline_Theme' => '*',
        'Weline_Widget' => '*',
    ],
    'provides' => [
        \Weline\Review\Api\ReviewSeoFactsInterface::class => \Weline\Review\Service\ReviewService::class,
    ],
];
