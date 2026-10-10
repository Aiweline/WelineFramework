<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\SearchHubService;
use Weline\Search\Service\SearchStorefrontCacheCoordinator;

final class SearchQueryResultCacheContractTest extends TestCase
{
    public function testQueryResultPolicyCoversCurrencyLangAreaAndSearchPool(): void
    {
        $policy = SearchStorefrontCacheCoordinator::queryResultPolicy();
        self::assertSame('search.query_result', $policy->resource);
        self::assertSame('search', $policy->pool);
        self::assertSame('channel', $policy->scope);
        self::assertSame(['area', 'currency', 'lang'], $policy->vary);
        self::assertContains('catalog', $policy->dependencies);
        self::assertGreaterThanOrEqual(2000, $policy->singleFlightWaitMs);
        self::assertTrue($policy->allowsEmptyResult);
    }

    public function testQueryResultLogicalKeySeparatesQTypePageAndAutocomplete(): void
    {
        $a = SearchStorefrontCacheCoordinator::queryResultLogicalKey('Hanfu', 'all', 1, 24, 'mysql', 'frontend');
        $b = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'all', 1, 24, 'mysql', 'frontend');
        $c = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'product', 1, 24, 'mysql', 'frontend');
        $d = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'all', 2, 24, 'mysql', 'frontend');
        $e = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'all', 1, 24, 'mysql', 'frontend', true);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertNotSame($a, $d);
        self::assertNotSame($a, $e);
    }

    public function testSearchHubWiresRememberPolicyForPageQueries(): void
    {
        $hub = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/SearchHubService.php');
        self::assertStringContainsString('queryResultPolicy', $hub);
        self::assertStringContainsString('queryResultLogicalKey', $hub);
        self::assertStringContainsString('rememberPolicy', $hub);
        self::assertStringContainsString('fromCacheArray', $hub);
        self::assertStringContainsString('Autocomplete is short-lived', $hub);
    }

    public function testSearchResultCacheRoundTripPreservesSections(): void
    {
        $hit = new SearchHit('product', 'product', '42', 'Title', '/p/42', ['sku' => 'X'], 1.5);
        $original = new SearchResult(
            ok: true,
            type: 'all',
            hits: [],
            hitCount: 1,
            sections: ['product' => [$hit]],
            meta: ['result_cache' => 'miss_build'],
            elapsedMs: 12.5,
            engine: 'mysql',
        );
        $restored = SearchResult::fromCacheArray($original->toCacheArray());
        self::assertTrue($restored->ok);
        self::assertSame('all', $restored->type);
        self::assertSame(1, $restored->hitCount);
        self::assertArrayHasKey('product', $restored->sections);
        self::assertSame('42', $restored->sections['product'][0]->entityId);
        self::assertSame('X', $restored->sections['product'][0]->payload['sku'] ?? null);
    }
}
