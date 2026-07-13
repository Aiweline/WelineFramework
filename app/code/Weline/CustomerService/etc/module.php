<?php

return [
    "name" => 'Weline_CustomerService',
    "version" => '1.2.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_Smtp' => '*',
        'Weline_Theme' => '*',
    ],
    "optional" => [
        'Weline_Ai' => '*',
    ],
    "provides" => [
        'template_cache_policy.Weline_CustomerService' => \Weline\CustomerService\Api\View\TemplateCachePolicyProvider::class,
    ],
];
