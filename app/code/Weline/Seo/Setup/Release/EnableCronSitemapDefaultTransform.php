<?php

declare(strict_types=1);

namespace Weline\Seo\Setup\Release;

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Service\Setup\CronSitemapDefaultSeeder;

/** Replaces legacy migration enable_cron_sitemap_default_on_20260922-v1.2.101. */
final class EnableCronSitemapDefaultTransform extends ServiceDataTransform
{
    public function __construct(CronSitemapDefaultSeeder $seeder, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'seo.enable_cron_sitemap_default',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            SeoAccount::schema_table,
            '1.2.100',
            '1.2.101',
            static function () use ($seeder): array {
                $seeder->ensure();

                return ['seeded' => true];
            },
        );
    }
}
