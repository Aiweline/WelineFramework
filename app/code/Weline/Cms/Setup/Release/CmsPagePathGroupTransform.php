<?php

declare(strict_types=1);

namespace Weline\Cms\Setup\Release;

use Weline\Cms\Model\Page;
use Weline\Cms\Service\Setup\PagePathGroupBackfiller;
use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;

/** Replaces legacy migration add_cms_site_path_groups_20260701-v1.0.2. */
final class CmsPagePathGroupTransform extends ServiceDataTransform
{
    public function __construct(PagePathGroupBackfiller $backfiller, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'cms.page_path_groups',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            Page::schema_table,
            '1.0.0',
            '1.0.2',
            static function () use ($backfiller): array {
                $backfiller->backfill();

                return ['backfilled' => true];
            },
        );
    }
}
