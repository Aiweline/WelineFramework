<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemePreviewRenderCache;

final class ThemePreviewRenderCacheTest extends TestCase
{
    public function testLogicalKeyIsStableForLegacyPreview(): void
    {
        $service = (new \ReflectionClass(ThemePreviewRenderCache::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ThemePreviewRenderCache::class, 'logicalKey');
        $method->setAccessible(true);

        $first = $method->invoke(
            $service,
            1,
            ThemeLayout::PAGE_TYPE_HOME,
            'default',
            ThemeLayout::STATUS_DRAFT,
            'default',
            null,
            null,
            'global',
            0,
        );
        $second = $method->invoke(
            $service,
            1,
            ThemeLayout::PAGE_TYPE_HOME,
            'default',
            ThemeLayout::STATUS_PUBLISHED,
            'default',
            null,
            null,
            'global',
            0,
        );

        self::assertStringStartsWith('theme.preview.shell.', $first);
        self::assertNotSame($first, $second);
    }

    public function testLogicalKeyIncludesThemePublicRouteFingerprint(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ThemePreviewRenderCache.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("'v7'", $source);
        self::assertStringContainsString('resolvePublicRouteFingerprint', $source);
        self::assertStringContainsString('resolveEditorModeFingerprint', $source);
        self::assertStringContainsString('theme_public_route', $source);

        $service = (new \ReflectionClass(ThemePreviewRenderCache::class))->newInstanceWithoutConstructor();
        $fingerprint = new \ReflectionMethod(ThemePreviewRenderCache::class, 'resolvePublicRouteFingerprint');
        $fingerprint->setAccessible(true);
        self::assertSame('-', $fingerprint->invoke($service));
    }
}
