<?php

return [
    "name" => 'Weline_Deploy',
    "version" => '1.1.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_ModuleRouter' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
        'Weline_Websites' => '*',
    ],
    "provides" => [
        'wls_panel.operation_definition.Weline_Deploy' => \Weline\Deploy\Integration\Server\WlsPanelOperationDefinitionProvider::class,
    ],
];
