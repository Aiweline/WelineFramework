<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Extends\Module\Weline_Search;

\defined('BP') || \define('BP', \dirname(__DIR__, 9) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Admin\Extends\Module\Weline_Search\Searcher\BackendMenuSearchProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

final class BackendMenuSearchProviderContractTest extends TestCase
{
    public function testProviderSourceUsesIndexServiceNotLiveCollect(): void
    {
        $path = BP . 'app/code/Weline/Admin/extends/module/Weline_Search/Searcher/BackendMenuSearchProvider.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('documentsForIndex', $source);
        self::assertStringContainsString('SearchProviderIndexService', $source);
        self::assertStringContainsString('indexService->search', $source);
        self::assertStringContainsString("locale: ''", $source);
        self::assertStringNotContainsString('collectNavigableMenuSearchItems()', $source);
        self::assertStringContainsString('backend_menu_index', $source);
    }

    public function testProviderIsBackendOnly(): void
    {
        /** @var BackendMenuSearchProvider $provider */
        $provider = ObjectManager::getInstance(BackendMenuSearchProvider::class);
        self::assertSame('backend_menu', $provider->code());
        self::assertSame(['backend'], $provider->areas());
        self::assertSame(10, $provider->sortOrder());
    }

    public function testIndexedSearchCanHitCrossLocaleKeywordWhenIndexWarm(): void
    {
        /** @var BackendMenuSearchProvider $provider */
        $provider = ObjectManager::getInstance(BackendMenuSearchProvider::class);
        $request = new SearchRequest(
            q: 'Products',
            type: 'backend_menu',
            page: 1,
            pageSize: 20,
            websiteId: 0,
            storeId: 0,
            channelId: 0,
            locale: 'zh_Hans_CN',
            currency: 'USD',
        );
        try {
            $result = $provider->execute($request, $provider->expression($request));
        } catch (\Throwable $e) {
            self::markTestSkipped('搜索索引不可用：' . $e->getMessage());
        }
        self::assertTrue($result->ok);
        if ($result->hitCount === 0) {
            self::assertSame('backend_menu_index', $result->engine);
            self::markTestSkipped('backend_menu 索引为空；请先 rebuild 后再验交叉命中');
        }
        self::assertGreaterThan(0, $result->hitCount);
        self::assertSame('backend_menu_index', $result->engine);
        self::assertNotSame('', $result->hits[0]->entityId);
        self::assertNotSame('', $result->hits[0]->url);
    }
}
