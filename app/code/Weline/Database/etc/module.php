<?php

return [
    "name" => 'Weline_Database',
    "version" => '1.2.0',
    "requires" => [
        'Weline_Backend' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Database\Api\ModuleRollbackManagerInterface::class => \Weline\Database\Service\ModuleRollbackManager::class,
    ],
];
