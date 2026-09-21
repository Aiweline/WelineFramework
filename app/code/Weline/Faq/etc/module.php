<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Faq',
    'version' => '1.0.16',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
        'Weline_Theme' => '*',
        'Weline_Cms' => '*',
        'Weline_Backend' => '*',
        'Weline_Widget' => '*',
    ],
    'optional' => [
        'Weline_Seo' => '*',
        'Weline_Product' => '*',
        'Weline_Search' => '*',
        'Weline_SystemConfig' => '*',
    ],
    'provides' => [
        \Weline\Faq\Api\FaqSeoFactsInterface::class => \Weline\Faq\Service\FaqService::class,
    ],
];
