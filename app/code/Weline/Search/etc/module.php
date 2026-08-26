<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Search',
    'version' => '1.4.3',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
        'Weline_Queue' => '*',
        'Weline_Product' => '*',
    ],
    'optional' => [],
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
    ],
];
