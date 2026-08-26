<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

final class StorefrontImagePlaceholderTest extends TestCase
{
    public function testUrlReturnsSingleSharedStaticSvgSrc(): void
    {
        $url = StorefrontImagePlaceholder::url(2);
        self::assertSame(StorefrontImagePlaceholder::url(99), $url);
        self::assertStringEndsWith('/images/storefront-placeholder/default.svg', $url);
        self::assertStringNotContainsString('data:image/', $url);
    }

    public function testResolveEmptyUsesSharedPlaceholder(): void
    {
        $resolved = StorefrontImagePlaceholder::resolve('', 1);
        self::assertSame($resolved['src'], $resolved['fallback']);
        self::assertStringEndsWith('/images/storefront-placeholder/default.svg', $resolved['src']);
    }

    public function testResolveKnownBrokenUsesSharedPlaceholder(): void
    {
        $broken = 'https://images.unsplash.com/photo-1524484485831-a92aec687147?w=640';
        $resolved = StorefrontImagePlaceholder::resolve($broken, 0);
        self::assertStringEndsWith('/images/storefront-placeholder/default.svg', $resolved['src']);
    }

    public function testResolveDataUriForbiddenAndReplacedWithStaticSrc(): void
    {
        $resolved = StorefrontImagePlaceholder::resolve('data:image/svg+xml;charset=UTF-8,x', 4);
        self::assertStringEndsWith('/images/storefront-placeholder/default.svg', $resolved['src']);
        self::assertFalse(StorefrontImagePlaceholder::isUsable('data:image/svg+xml;charset=UTF-8,x'));
    }

    public function testResolveUsableKeepsSrc(): void
    {
        $ok = 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=640';
        $resolved = StorefrontImagePlaceholder::resolve($ok, 3);
        self::assertSame($ok, $resolved['src']);
        self::assertStringEndsWith('/images/storefront-placeholder/default.svg', $resolved['fallback']);
    }
}
