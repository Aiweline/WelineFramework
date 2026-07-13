<?php

return [
    "name" => 'Weline_Hook',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
    ],
    "provides" => [
        \Weline\Framework\Registry\ExtensionRegistryRefresherInterface::class => \Weline\Hook\Api\RegistryRefresher::class,
    ],
];
