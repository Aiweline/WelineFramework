<?php

return [
    "name" => 'Weline_Location',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Framework' => '*',
        'Weline_Theme' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'template_cache_policy.Weline_Location' => \Weline\Location\Api\View\TemplateCachePolicyProvider::class,
    ],
];
