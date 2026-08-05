<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Integration\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Service\PageService;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Manager\ObjectManager;

final class PageServiceSlugRollbackProbe extends \RuntimeException
{
}

final class PageServiceSlugPersistenceIntegrationTest extends TestCase
{
    public function testSourceTitleDrivesAutoSlugUntilPublishAndRollsBackTogether(): void
    {
        if (getenv('WELINE_TEST_PRIMARY_PGSQL') !== '1') {
            self::markTestSkipped('Set WELINE_TEST_PRIMARY_PGSQL=1 for the explicit CMS PostgreSQL gate.');
        }

        $pagePrototype = ObjectManager::getInstance(Page::class);
        $pageService = ObjectManager::getInstance(PageService::class);
        $connection = $pagePrototype->getConnection();
        self::assertSame('pgsql', strtolower($connection->getConnector()->getConfigProvider()->getDbType()));

        $token = bin2hex(random_bytes(8));
        $pathGroup = 'slug-integration-' . $token;
        $finalIdentifier = $pathGroup . '/our-company';
        $savedPageId = 0;

        try {
            (new TransactionCoordinator())->run($connection, function () use (
                $pageService,
                $pathGroup,
                &$savedPageId
            ): never {
                $page = $pageService->savePage([
                    'website_id' => 0,
                    'website_code' => 'default',
                    'path_group' => $pathGroup,
                    'path_group_alias' => 'Slug integration',
                    'slug' => '20260729052847-c6796ab7',
                    'slug_mode' => Page::SLUG_MODE_AUTO,
                    'title' => 'About Our Team',
                    'locale_code' => 'en_US',
                    'scope' => 'default',
                    'status' => Page::STATUS_DRAFT,
                ]);
                $savedPageId = $page->getPageId();
                self::assertGreaterThan(0, $savedPageId);
                self::assertSame('about-our-team', $page->getSlug());
                self::assertSame($pathGroup . '/about-our-team', $page->getIdentifier());
                self::assertSame(Page::SLUG_MODE_AUTO, $page->getSlugMode());
                self::assertSame(hash('sha256', 'About Our Team'), $page->getSlugSourceHash());

                $page = $pageService->savePage([
                    'page_id' => $savedPageId,
                    'website_id' => 0,
                    'website_code' => 'default',
                    'path_group' => $pathGroup,
                    'path_group_alias' => 'Slug integration',
                    'slug' => $page->getSlug(),
                    'slug_mode' => Page::SLUG_MODE_AUTO,
                    'title' => 'Our Company',
                    'locale_code' => 'en_US',
                    'scope' => 'default',
                    'status' => Page::STATUS_DRAFT,
                ]);
                self::assertSame('our-company', $page->getSlug());
                self::assertSame($pathGroup . '/our-company', $page->getIdentifier());
                self::assertSame(Page::SLUG_MODE_AUTO, $page->getSlugMode());

                $page = $pageService->savePage([
                    'page_id' => $savedPageId,
                    'website_id' => 0,
                    'website_code' => 'default',
                    'path_group' => $pathGroup,
                    'path_group_alias' => 'Slug integration',
                    'slug' => $page->getSlug(),
                    'slug_mode' => Page::SLUG_MODE_AUTO,
                    'title' => 'Our Company',
                    'locale_code' => 'en_US',
                    'scope' => 'default',
                    'status' => Page::STATUS_PUBLISHED,
                ]);
                self::assertSame('our-company', $page->getSlug());
                self::assertSame(Page::SLUG_MODE_FROZEN, $page->getSlugMode());

                $page = $pageService->savePage([
                    'page_id' => $savedPageId,
                    'website_id' => 0,
                    'website_code' => 'default',
                    'path_group' => $pathGroup,
                    'path_group_alias' => 'Slug integration',
                    'slug' => $page->getSlug(),
                    'title' => 'Renamed After Publish',
                    'locale_code' => 'en_US',
                    'scope' => 'default',
                    'status' => Page::STATUS_DRAFT,
                ]);
                self::assertSame('our-company', $page->getSlug());
                self::assertSame(Page::SLUG_MODE_FROZEN, $page->getSlugMode());
                self::assertSame(hash('sha256', 'Renamed After Publish'), $page->getSlugSourceHash());

                throw new PageServiceSlugRollbackProbe('cms-page-service-slug-rollback-probe');
            });
            self::fail('Rollback probe was not raised.');
        } catch (PageServiceSlugRollbackProbe $exception) {
            self::assertSame('cms-page-service-slug-rollback-probe', $exception->getMessage());
        }

        $rolledBackPage = clone $pagePrototype;
        $rolledBackPage->clearData()->reset()
            ->where(Page::schema_fields_IDENTIFIER, $finalIdentifier)
            ->where(Page::schema_fields_WEBSITE_ID, 0)
            ->find()
            ->fetch();
        self::assertSame(0, $rolledBackPage->getPageId());

        if ($savedPageId > 0) {
            $rolledBackById = clone $pagePrototype;
            $rolledBackById->clearData()->reset()
                ->where(Page::schema_fields_ID, $savedPageId)
                ->find()
                ->fetch();
            self::assertSame(0, $rolledBackById->getPageId());
        }
    }
}
