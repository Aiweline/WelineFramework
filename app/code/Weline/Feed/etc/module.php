<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Feed',
    'version' => '0.1.1',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Product' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [
        'Weline_Mail' => '*',
    ],
    'provides' => [
        \Weline\Feed\Api\FeedSourceInterface::class
            => \Weline\Feed\Service\CatalogFeedSource::class,
        \Weline\Feed\Api\FeedDefinitionRepositoryInterface::class
            => \Weline\Feed\Service\Orm\OrmFeedDefinitionRepository::class,
        \Weline\Feed\Api\FeedArtifactWriterInterface::class
            => \Weline\Feed\Service\Orm\OrmFeedArtifactWriter::class,
        \Weline\Feed\Api\FeedPublicSnapshotSourceInterface::class
            => \Weline\Feed\Service\Orm\OrmFeedPublicSnapshotSource::class,
    ],
];
