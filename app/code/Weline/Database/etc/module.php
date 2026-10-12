<?php

return [
    "name" => 'Weline_Database',
    "version" => '1.2.7',
    "requires" => [
        'Weline_Backend' => '*',
    ],
    "optional" => [
        'Weline_ModuleManager' => '*',
    ],
    "provides" => [
        \Weline\Database\Api\ModuleRollbackManagerInterface::class => \Weline\Database\Service\ModuleRollbackManager::class,
    ],
];
