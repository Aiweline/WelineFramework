<?php

return [
    "name" => 'Weline_Geo',
    "version" => '1.0.23',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Queue' => '*',
        'Weline_Seo' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'template_cache_policy.Weline_Geo' => \Weline\Geo\Api\View\TemplateCachePolicyProvider::class,
    ],
];
