<?php

return [
    "name" => 'Weline_SystemConfig',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\SystemConfig\Api\Scope\ScopedConfigRepositoryInterface::class => \Weline\SystemConfig\Service\ScopedConfigRepository::class,
    ],
];
