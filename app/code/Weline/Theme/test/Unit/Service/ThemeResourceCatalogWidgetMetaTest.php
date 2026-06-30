<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeResourceCatalog;

final class ThemeResourceCatalogWidgetMetaTest extends TestCase
{
    public function testExtractWidgetMetaParsesBraceWrappedValues(): void
    {
        $resolver = $this->createMock(ThemeDirectoryResolver::class);
        $catalog = new ThemeResourceCatalog($resolver);

        $method = new \ReflectionMethod($catalog, 'extractWidgetMeta');
        $method->setAccessible(true);

        $content = <<<'PHTML'
<?php
/**
 * @widget.code {logo}
 * @widget.name {Logo}
 * @widget.description {Header Logo}
 * @widget.type {header}
 * @widget.position {["header"]}
 * @widget.supports {["layout-header-logo","layout-global-header-logo"]}
 * @widget.slot {logo}
 * @widget.page_layouts {["*"]}
 * @widget.exclusive {true}
 * @widget.compatible {true}
 * @widget.is_container {false}
 */
PHTML;

        /** @var array<string,mixed> $meta */
        $meta = $method->invoke($catalog, $content, 'fallback-code');

        self::assertSame('logo', $meta['code']);
        self::assertSame('header', $meta['type']);
        self::assertSame(['header'], $meta['position']);
        self::assertSame(['layout-header-logo', 'layout-global-header-logo'], $meta['supports']);
        self::assertSame('logo', $meta['slot']);
        self::assertSame(['*'], $meta['page_layouts']);
        self::assertTrue($meta['exclusive']);
        self::assertTrue($meta['compatible']);
        self::assertFalse($meta['is_container']);
    }
}
