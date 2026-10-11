<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\SearchQueryResultTipCacheWarmer;

/** Guard: Search tip query_result warmer provides + Clear warmUp search without FQCN soft-pull. */
final class SearchQueryResultTipCacheWarmerContractTest extends TestCase
{
    public function testModuleProvidesTipQueryResultWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertSame(
            SearchQueryResultTipCacheWarmer::class,
            $provides['cache.warmer.search.query_result_tip'] ?? null,
        );
        self::assertSame('1.4.30', (string)($module['version'] ?? ''));
    }

    public function testWarmerTargetsSearchPoolWithBoundedPriority(): void
    {
        $warmer = new SearchQueryResultTipCacheWarmer();
        self::assertSame('search', $warmer->getTargetPool());
        self::assertSame('search.query_result_tip', $warmer->getName());
        self::assertSame(225, $warmer->getPriority());
        self::assertGreaterThan(220, $warmer->getPriority());
        self::assertLessThan(230, $warmer->getPriority());
    }

    public function testClearWarmsSearchPoolWithoutSearchFqcn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        self::assertStringContainsString("warmUp('search')", $src);
        self::assertStringNotContainsString('SearchQueryResultTipCacheWarmer', $src);
    }

    public function testWarmerIsBoundedTipNotFullQuerySpace(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SearchQueryResultTipCacheWarmer.php'
        );
        self::assertStringContainsString('TIP_QS', $src);
        self::assertStringContainsString("'hanfu'", $src);
        self::assertStringContainsString("'silk'", $src);
        self::assertStringContainsString('TIP_LANGS', $src);
        self::assertStringContainsString('TIP_CURRENCIES', $src);
        self::assertStringContainsString('TIP_SCOPES', $src);
        self::assertStringContainsString("'bn_BD'", $src);
        self::assertStringContainsString("'GBP'", $src);
        self::assertStringContainsString('queryResultPolicy', $src);
        self::assertStringContainsString('queryResultLogicalKey', $src);
        self::assertStringContainsString('SearchHubService', $src);
        self::assertStringContainsString('never warm the full query space', $src);
        self::assertStringContainsString('Sticky hit2 alone is NOT an acceptance pass', $src);
        self::assertStringNotContainsString('getWebsiteLanguageCodes', $src);
        self::assertStringNotContainsString('HotWordsService', $src);
        self::assertStringNotContainsString('static $', $src);
    }

    public function testProjectionWarmerDocumentsNotQueryResultHit(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Product/Service/SearchProjectionScopeCacheWarmer.php'
        );
        self::assertStringContainsString('NOT proof that tip search.query_result', $src);
        self::assertStringContainsString('SearchQueryResultTipCacheWarmer', $src);
    }
}
