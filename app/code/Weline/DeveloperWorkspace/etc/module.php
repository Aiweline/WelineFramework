<?php

return [
    "name" => 'Weline_DeveloperWorkspace',
    "version" => '1.3.0',
    "requires" => [
        'Weline_Ai' => '*',
        'Weline_Backend' => '*',
        'Weline_Cron' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
        'Weline_Api' => '*',
        'Weline_Seo' => '*',
        'Weline_Server' => '*',
        'Weline_Visitor' => '*',
        'Weline_Websites' => '*',
    ],
    "provides" => [
        \Weline\Framework\Runtime\DeveloperAccessProviderInterface::class => \Weline\DeveloperWorkspace\Api\Runtime\DeveloperAccessProvider::class,
    ],
];
