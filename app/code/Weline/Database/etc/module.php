<?php

return [
    "name" => 'Weline_Database',
    "version" => '1.2.9',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
        'Weline_ModuleManager' => '*',
        'Weline_Queue' => '*',
    ],
    "provides" => [
        \Weline\Database\Api\ModuleRollbackManagerInterface::class => \Weline\Database\Service\ModuleRollbackManager::class,
        \Weline\Framework\Database\Schema\SchemaIndexChangeSinkInterface::class
            => \Weline\Database\Service\Index\SchemaIndexChangeSink::class,
    ],
];
