<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Extends\Module\Weline_Search;

\defined('BP') || \define('BP', \dirname(__DIR__, 9) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Admin\Extends\Module\Weline_Search\Searcher\BackendMenuSearchProvider;
use Weline\Admin\Service\MenuRenderService;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

final class BackendMenuSearchProviderContractTest extends TestCase
{
    public function testProviderIsBackendOnlyAndMatchesCrossLocaleSearchText(): void
    {
        $menuRender = $this->createMock(MenuRenderService::class);
        $menuRender->method('collectNavigableMenuSearchItems')->willReturn([
            [
                'source_id' => 'Weline_Product::catalog',
                'title' => '商品',
                'url' => '/backend/product/backend/catalog/index',
                'search_text' => 'Products 商品',
            ],
        ]);

        $provider = new BackendMenuSearchProvider($menuRender);
        self::assertSame('backend_menu', $provider->code());
        self::assertSame(['backend'], $provider->areas());
        self::assertSame(10, $provider->sortOrder());

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
        $result = $provider->execute($request, SearchExpression::of($request)->match(['title']));
        self::assertTrue($result->ok);
        self::assertCount(1, $result->hits);
        self::assertSame('商品', $result->hits[0]->title);
        self::assertSame('Weline_Product::catalog', $result->hits[0]->entityId);
    }
}
