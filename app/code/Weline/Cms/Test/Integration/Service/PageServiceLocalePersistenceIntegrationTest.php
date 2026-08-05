<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Integration\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Cms\Service\PageService;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Manager\ObjectManager;

final class PageServiceLocaleRollbackProbe extends \RuntimeException
{
}

final class PageServiceLocalePersistenceIntegrationTest extends TestCase
{
    public function testSavePagePersistsSourceLocaleBeforeSideEffectsAndRollsBackTogether(): void
    {
        if (getenv('WELINE_TEST_PRIMARY_PGSQL') !== '1') {
            self::markTestSkipped('Set WELINE_TEST_PRIMARY_PGSQL=1 for the explicit CMS PostgreSQL gate.');
        }

        $pagePrototype = ObjectManager::getInstance(Page::class);
        $localePrototype = ObjectManager::getInstance(PageLocale::class);
        $pageService = ObjectManager::getInstance(PageService::class);
        $connection = $pagePrototype->getConnection();
        self::assertSame('pgsql', strtolower($connection->getConnector()->getConfigProvider()->getDbType()));

        $token = bin2hex(random_bytes(8));
        $pathGroup = 'locale-integration-' . $token;
        $identifier = $pathGroup . '/localized-page';
        $savedPageId = 0;

        try {
            (new TransactionCoordinator())->run($connection, function () use (
                $pageService,
                $localePrototype,
                $pathGroup,
                &$savedPageId
            ): never {
                $page = $pageService->savePage([
                    'website_id' => 0,
                    'website_code' => 'default',
                    'path_group' => $pathGroup,
                    'path_group_alias' => 'Locale integration',
                    'slug' => 'localized-page',
                    'title' => 'English source title',
                    'locale_code' => 'en_US',
                    'scope' => 'default',
                    'status' => Page::STATUS_DRAFT,
                ]);
                $savedPageId = $page->getPageId();
                self::assertGreaterThan(0, $savedPageId);
                self::assertSame('en_US', $page->getSourceLocale());

                $rows = (clone $localePrototype)->clearData()->reset()
                    ->where(PageLocale::schema_fields_PAGE_ID, $savedPageId)
                    ->where(PageLocale::schema_fields_LOCALE_CODE, 'en_US')
                    ->select()
                    ->fetch()
                    ->getItems();
                self::assertCount(1, $rows);
                self::assertSame('English source title', $rows[0]->getTitle());

                throw new PageServiceLocaleRollbackProbe('cms-page-service-locale-rollback-probe');
            });
            self::fail('Rollback probe was not raised.');
        } catch (PageServiceLocaleRollbackProbe $exception) {
            self::assertSame('cms-page-service-locale-rollback-probe', $exception->getMessage());
        }

        $rolledBackPage = clone $pagePrototype;
        $rolledBackPage->clearData()->reset()
            ->where(Page::schema_fields_IDENTIFIER, $identifier)
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
