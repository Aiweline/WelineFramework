<?php

return [
    "name" => 'Weline_CustomerService',
    "version" => '1.3.73',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_Smtp' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Ai' => '*',
    ],
    "provides" => [
        'template_cache_policy.Weline_CustomerService' => \Weline\CustomerService\Api\View\TemplateCachePolicyProvider::class,
    ],
];
