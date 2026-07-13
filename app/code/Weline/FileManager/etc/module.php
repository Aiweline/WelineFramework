<?php

return [
    "name" => 'Weline_FileManager',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Eav' => '*',
        'Weline_Queue' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
    ],
    "provides" => [
        'wls_panel.operation_definition.Weline_FileManager' => \Weline\FileManager\Integration\Server\WlsPanelOperationDefinitionProvider::class,
    ],
];
