<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontListingPager;

/**
 * Listing pager prev/next must keep Chinese source; templates resolve via
 * WidgetI18n at render so /hi_IN/ PLP does not keep Chinese chrome.
 */
final class StorefrontListingPagerLocaleContractTest extends TestCase
{
    public function testPagerKeepsChineseSourceWithoutControllerTranslate(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontListingPager.php'
        );

        self::assertStringContainsString("'label' => '上一页'", $source);
        self::assertStringContainsString("'label' => '下一页'", $source);
        self::assertStringNotContainsString("__('上一页')", $source);
        self::assertStringNotContainsString("__('下一页')", $source);
        self::assertStringNotContainsString('use Weline\\Theme\\Helper\\WidgetI18n;', $source);
    }

    public function testPagerPrevNextLabelsAreChineseSource(): void
    {
        $pager = new StorefrontListingPager();
        $options = $pager->buildPageOptions('/products', 2, 5, [], 5, true);
        self::assertSame('prev', $options[0]['type']);
        self::assertSame('上一页', $options[0]['label']);
        $last = $options[array_key_last($options)];
        self::assertSame('next', $last['type']);
        self::assertSame('下一页', $last['label']);
    }

    public function testCategoryAndCatalogTemplatesReResolvePrevNextAtRender(): void
    {
        $category = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/category/index.phtml'
        );
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/catalog/index.phtml'
        );

        foreach ([$category, $catalog] as $source) {
            self::assertStringContainsString('use Weline\\Theme\\Helper\\WidgetI18n;', $source);
            self::assertStringContainsString("WidgetI18n::label('上一页')", $source);
            self::assertStringContainsString("WidgetI18n::label('下一页')", $source);
        }
    }
}
