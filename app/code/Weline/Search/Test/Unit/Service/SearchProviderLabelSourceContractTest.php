<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Search type labels in hot-cache must stay stable Chinese (or FAQ) source keys.
 * Storefront WidgetI18n translates at render — providers must not __() bake a locale.
 */
final class SearchProviderLabelSourceContractTest extends TestCase
{
    public function testRegistryBuildsAllLabelAsChineseSource(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SearchProviderRegistry.php'
        );
        self::assertStringContainsString("'label' => '全部'", $source);
        self::assertStringNotContainsString("__('全部')", $source);
        self::assertStringContainsString('search.provider_types.v5.', $source);
        self::assertStringNotContainsString('search.provider_types.v4.', $source);
    }

    public function testFrontendProvidersReturnStableSourcesWithoutPhraseBake(): void
    {
        $product = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Product/extends/module/Weline_Search/Searcher/ProductSearchProvider.php'
        );
        $blog = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Blog/extends/module/Weline_Search/Searcher/BlogSearchProvider.php'
        );
        $faq = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Faq/extends/module/Weline_Search/Searcher/FaqSearchProvider.php'
        );

        self::assertStringContainsString("return '商品';", $product);
        self::assertStringNotContainsString("__('商品')", $product);
        self::assertStringContainsString("return '博客';", $blog);
        self::assertStringNotContainsString("__('博客')", $blog);
        self::assertStringContainsString("return '常问问题';", $faq);
        self::assertStringNotContainsString("__('FAQ')", $faq);
        self::assertStringNotContainsString("return 'FAQ';", $faq);
    }
}
