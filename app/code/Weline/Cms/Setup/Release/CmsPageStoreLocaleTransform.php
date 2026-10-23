<?php

declare(strict_types=1);

namespace Weline\Cms\Setup\Release;

use Weline\Cms\Model\PageLocale;
use Weline\Cms\Service\Setup\PageStoreLocaleMigrator;
use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;

/** Replaces legacy migration add_cms_page_store_locale_variants_20260822-v1.1.0. */
final class CmsPageStoreLocaleTransform extends ServiceDataTransform
{
    public function __construct(PageStoreLocaleMigrator $migrator, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'cms.page_store_locale_variants',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            PageLocale::schema_table,
            '1.0.0',
            '1.1.0',
            static function () use ($migrator): array {
                $migrator->migrate();

                return ['migrated' => true];
            },
        );
    }
}
