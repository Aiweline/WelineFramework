<?php

return [
    "name" => 'Weline_DbManager',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Server' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'wls_panel.operation_definition.Weline_DbManager' => \Weline\DbManager\Integration\Server\WlsPanelOperationDefinitionProvider::class,
    ],
];
