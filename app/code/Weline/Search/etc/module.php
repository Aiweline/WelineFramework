<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Search',
    'version' => '1.4.17',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
        'Weline_Queue' => '*',
    ],
    'optional' => [
        'Weline_Product' => '*',
    ],
    'provides' => [
        \Weline\Search\Api\SearchShardRegistryInterface::class
            => \Weline\Search\Model\SearchShardRegistry::class,
        \Weline\Search\Api\SearchIndexStorageInterface::class
            => \Weline\Search\Service\DatabaseSearchIndexStore::class,
        \Weline\Search\Api\ProductSearchProjectionSourceInterface::class
            => \Weline\Search\Service\ProductQuerySearchProjectionSource::class,
        \Weline\Search\Api\ProductDirectCatalogReaderInterface::class
            => \Weline\Search\Service\ProductProjectionDirectCatalogReader::class,
        \Weline\Search\Api\SearchDegradeMarkerStoreInterface::class
            => \Weline\Search\Service\DatabaseSearchDegradeMarkerStore::class,
        \Weline\Search\Api\SearchProviderIndexStorageInterface::class
            => \Weline\Search\Service\DatabaseSearchProviderIndexStore::class,
        \Weline\Search\Api\SearchProjectionPendingDrainerInterface::class
            => \Weline\Search\Service\SearchProjectionPendingDrainer::class,
        \Weline\Search\Api\SearchProjectionQueueAdmissionInterface::class
            => \Weline\Search\Service\SearchProjectionQueueAdmission::class,
    ],
];
