<?php

return [
    "name" => 'Weline_PhpManager',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Server' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'wls_panel.operation_definition.Weline_PhpManager' => \Weline\PhpManager\Integration\Server\WlsPanelOperationDefinitionProvider::class,
    ],
];
