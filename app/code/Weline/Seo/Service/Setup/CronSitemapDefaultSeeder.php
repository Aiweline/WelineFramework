<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Setup;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;

/** Idempotent data half of enable_cron_sitemap_default_on_20260922-v1.2.101. */
final class CronSitemapDefaultSeeder
{
    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    public function ensure(): void
    {
        $connection = $this->connectionFactory->getConnection();
        $accountTable = ObjectManager::getInstance(SeoAccount::class)->getTable();
        $bindingTable = ObjectManager::getInstance(SeoWebsiteAccount::class)->getTable();

        if ($connection->tableExist($accountTable)) {
            $this->update($connection, $accountTable, [
                SeoAccount::schema_fields_ENABLE_CRON_SITEMAP,
                SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS,
            ]);
        }
        if ($connection->tableExist($bindingTable)) {
            $this->update($connection, $bindingTable, [
                SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT,
                SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH,
            ]);
        }
    }

    /** @param list<string> $columns */
    private function update(object $connection, string $table, array $columns): void
    {
        foreach ($columns as $column) {
            try {
                $connection->query(sprintf(
                    'UPDATE %s SET %s = 1 WHERE %s = 0 OR %s IS NULL',
                    $table,
                    $column,
                    $column,
                    $column,
                ));
            } catch (Throwable) {
                // Column may be absent in an old environment; schema sync owns adding it.
            }
        }
    }
}
