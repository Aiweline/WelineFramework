<?php

return [
    "name" => 'Weline_Storage',
    "version" => '1.2.1',
    "requires" => [
        'Weline_Framework' => '>=2.5.0',
    ],
    "optional" => [
        // MediaUrlCowResolver：有则按 Scope COW 媒体基址；无则回退共享 base_url
        'Weline_Cdn' => '*',
    ],
    "provides" => [
        \Weline\Storage\Api\StorageManagerInterface::class => \Weline\Storage\Service\StorageManagerV2::class,
        \Weline\Storage\Api\StorageCatalogInterface::class => \Weline\Storage\Service\StorageCatalog::class,
        \Weline\Storage\Api\StorageDirectoryManagerInterface::class => \Weline\Storage\Service\StorageDirectoryManager::class,
        \Weline\Storage\Api\StorageConfigSnapshotGuardInterface::class => \Weline\Storage\Service\StorageConfigSnapshotGuard::class,
        \Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface::class => \Weline\Storage\Service\StorageRequestResourceRegistry::class,
        \Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface::class => \Weline\Storage\Service\StorageRequestResourceFactory::class,
        \Weline\Storage\Api\Runtime\StorageRuntimeDiagnosticsReporterInterface::class => \Weline\Storage\Service\StorageRuntimeDiagnosticsReporter::class,
        'storage.driver_provider.local_filesystem' => \Weline\Storage\Provider\LocalFilesystemProvider::class,
        'request_resetter.Weline_Storage' => \Weline\Storage\Service\StorageRequestResetter::class,
    ],
];
