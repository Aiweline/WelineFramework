<?php

return [
    "name" => 'Weline_FileManager',
    "version" => '1.1.1',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Eav' => '*',
        'Weline_Queue' => '*',
        'Weline_Storage' => '>=1.2.0',
    ],
    "optional" => [
        'Weline_Server' => '*',
        'Weline_Theme' => '*',
    ],
    "provides" => [
        'storage.disk_usage_guard.file_assets' => \Weline\FileManager\Service\FileAssetStorageUsageGuard::class,
        \Weline\FileManager\Api\FileAssetManagerInterface::class => \Weline\FileManager\Service\FileAssetManager::class,
        \Weline\FileManager\Api\FileAssetLibraryInterface::class => \Weline\FileManager\Service\FileAssetLibrary::class,
        \Weline\FileManager\Api\FileAccessPolicyInterface::class => \Weline\FileManager\Service\FileAccessPolicy::class,
        \Weline\FileManager\Api\LayoutContentValidatorInterface::class => \Weline\FileManager\Service\LayoutContentValidator::class,
        'theme.layout_content_validator.file_assets' => \Weline\FileManager\Extends\Module\Weline_Theme\Integration\FileAssetLayoutContentValidator::class,
        'theme.layout_value_hydrator.file_image' => \Weline\FileManager\Extends\Module\Weline_Theme\Integration\FileImageLayoutValueHydrator::class,
        'wls_panel.operation_definition.Weline_FileManager' => \Weline\FileManager\Integration\Server\WlsPanelOperationDefinitionProvider::class,
    ],
];
