<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Integration\Model;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Cms\Service\PageLocaleService;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Manager\ObjectManager;

final class PageLocalePersistenceIntegrationTest extends TestCase
{
    public function testPostgreSqlUniqueUpsertSourceSyncAndRollback(): void
    {
        if (getenv('WELINE_TEST_PRIMARY_PGSQL') !== '1') {
            self::markTestSkipped('Set WELINE_TEST_PRIMARY_PGSQL=1 for the explicit CMS PostgreSQL gate.');
        }

        $pagePrototype = ObjectManager::getInstance(Page::class);
        $localePrototype = ObjectManager::getInstance(PageLocale::class);
        $connection = $pagePrototype->getConnection();
        $connector = $connection->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));

        $schema = (new DbSchemaReader())->readTable($connector, PageLocale::schema_table);
        self::assertNotNull($schema, 'Run the targeted Page/PageLocale schema sync before this gate.');
        $uniqueIndex = null;
        $expectedUniqueIdentity = IndexDefinitionContract::physicalIdentity(
            $connector,
            PageLocale::schema_table,
            'uk_cms_page_locale_page_code',
        );
        foreach ($schema->indexes as $index) {
            if ($index instanceof IndexDefinition
                && strtolower($index->name) === $expectedUniqueIdentity
            ) {
                $uniqueIndex = $index;
                break;
            }
        }
        self::assertNotNull($uniqueIndex);
        self::assertSame(['page_id', 'locale_code'], $uniqueIndex->columns);
        self::assertSame('UNIQUE', strtoupper($uniqueIndex->type));

        $service = new PageLocaleService(
            pageLocaleModel: $localePrototype,
            queryExecutor: static fn(
                string $provider,
                string $operation,
                array $params
            ): array => $operation === 'getWebsiteLanguageCodes'
                ? ['zh_Hans_CN', 'en_US']
                : ['website_id' => $params['website_id'], 'default_language' => 'zh_Hans_CN']
        );
        $identifier = 'cms-locale-pg-' . bin2hex(random_bytes(8));
        $savedPageId = 0;

        try {
            (new TransactionCoordinator())->run($connection, function () use (
                $pagePrototype,
                $localePrototype,
                $service,
                $identifier,
                &$savedPageId
            ): never {
                $page = clone $pagePrototype;
                $page->clearData()->setData([
                    Page::schema_fields_WEBSITE_ID => 0,
                    Page::schema_fields_WEBSITE_CODE => 'default',
                    Page::schema_fields_PATH_GROUP => 'integration',
                    Page::schema_fields_PATH_GROUP_ALIAS => 'Integration',
                    Page::schema_fields_SLUG => $identifier,
                    Page::schema_fields_IDENTIFIER => 'integration/' . $identifier,
                    Page::schema_fields_TITLE => 'Legacy title',
                    Page::schema_fields_SOURCE_LOCALE => 'en_US',
                    Page::schema_fields_STATUS => Page::STATUS_DRAFT,
                    Page::schema_fields_SCOPE => 'default',
                ])->save();
                $savedPageId = $page->getPageId();
                self::assertGreaterThan(0, $savedPageId);

                $service->upsertTitle($page, 'en_US', 'English title', PageLocale::ORIGIN_SOURCE);
                $service->upsertTitle($page, 'en_US', 'Updated English title', PageLocale::ORIGIN_MANUAL);

                $rows = (clone $localePrototype)->clearData()->reset()
                    ->where(PageLocale::schema_fields_PAGE_ID, $savedPageId)
                    ->where(PageLocale::schema_fields_LOCALE_CODE, 'en_US')
                    ->select()
                    ->fetch()
                    ->getItems();
                self::assertCount(1, $rows);
                self::assertSame('Updated English title', $rows[0]->getTitle());
                self::assertSame('Updated English title', $page->getTitle());

                throw new \RuntimeException('cms-page-locale-rollback-probe');
            });
            self::fail('Rollback probe was not raised.');
        } catch (\RuntimeException $exception) {
            self::assertSame('cms-page-locale-rollback-probe', $exception->getMessage());
        }

        $rolledBackPage = clone $pagePrototype;
        $rolledBackPage->clearData()->reset()
            ->where(Page::schema_fields_IDENTIFIER, 'integration/' . $identifier)
            ->find()
            ->fetch();
        self::assertSame(0, $rolledBackPage->getPageId());

        if ($savedPageId > 0) {
            $rolledBackLocales = (clone $localePrototype)->clearData()->reset()
                ->where(PageLocale::schema_fields_PAGE_ID, $savedPageId)
                ->select()
                ->fetch()
                ->getItems();
            self::assertCount(0, $rolledBackLocales);
        }
    }
}
