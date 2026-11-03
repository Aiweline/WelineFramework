<?php

return [
    "name" => 'Weline_DeveloperWorkspace',
    "version" => '1.3.35',
    "requires" => [
        'Weline_Ai' => '*',
        'Weline_Backend' => '*',
        'Weline_Cron' => '*',
        'Weline_Deploy' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
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
