<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Cold-locale storefront labels are prefetched once before widget label() calls.
 */
final class StorefrontLabelPrefetchTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testPrefetchBatchesTheFixedSourceList(): void
    {
        $source = (string)file_get_contents(
            self::root() . '/app/code/Weline/Theme/Helper/StorefrontLabelPrefetch.php'
        );

        self::assertStringContainsString('WidgetI18n::prefetchLabels(self::SOURCES);', $source);
        self::assertStringContainsString("'店铺通知'", $source);
        self::assertStringContainsString("'批发信用'", $source);
        self::assertStringContainsString("'进店音乐'", $source);
        self::assertStringContainsString("'优惠券无效、不可用或已达使用上限'", $source);
    }

    public function testSharedShellPrefetchesBeforeHeaderLabels(): void
    {
        $source = (string)file_get_contents(
            self::root() . '/app/design/Weline/hanfu/frontend/partials/header/storefront-shell.phtml'
        );

        $prefetch = strpos($source, 'StorefrontLabelPrefetch::prefetch();');
        $headerEsc = strpos($source, '$headerEsc = static function');
        self::assertNotFalse($prefetch);
        self::assertNotFalse($headerEsc);
        self::assertLessThan($headerEsc, $prefetch);
        self::assertStringNotContainsString('WidgetI18n::prefetchLabels([', $source);
    }

    public function testHomepageLayoutsPrefetchBeforeBodyWidgets(): void
    {
        foreach (['default', 'minimal'] as $layout) {
            $source = (string)file_get_contents(
                self::root() . '/app/design/Weline/hanfu/frontend/layouts/homepage/' . $layout . '.phtml'
            );
            self::assertStringContainsString(
                'StorefrontLabelPrefetch::prefetch();',
                $source,
                $layout . ' homepage layout must prefetch fixed labels'
            );
        }
    }
}
