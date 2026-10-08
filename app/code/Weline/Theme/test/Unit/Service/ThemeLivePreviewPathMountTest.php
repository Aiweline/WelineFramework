<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeLivePreviewPathMount;

final class ThemeLivePreviewPathMountTest extends TestCase
{
    private const SAMPLE_TOKEN = 'pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    public function testParseFromUriExtractsTokenAndRemainder(): void
    {
        $parsed = ThemeLivePreviewPathMount::parseFromUri(
            '/~preview/' . self::SAMPLE_TOKEN . '/~site/grocery/about?keep=1'
        );
        self::assertNotNull($parsed);
        self::assertSame(self::SAMPLE_TOKEN, $parsed['token']);
        self::assertSame('/~preview/' . self::SAMPLE_TOKEN, $parsed['mount']);
        self::assertSame('/~site/grocery/about?keep=1', $parsed['remainder']);
    }

    public function testJoinPreviewPathStripsProjectHostSiteMount(): void
    {
        $url = ThemeLivePreviewPathMount::joinPreviewPath(
            'https://p05113ef3.test.weline.com/~site/grocery/about',
            self::SAMPLE_TOKEN,
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/~preview/' . self::SAMPLE_TOKEN . '/about',
            $url
        );
    }

    public function testJoinPreviewPathStripsLegacyQueryTokenAndCanvasKeys(): void
    {
        $url = ThemeLivePreviewPathMount::joinPreviewPath(
            'https://example.test/about?weline_preview_token=old&editor_mode=1&keep=1',
            self::SAMPLE_TOKEN,
        );
        self::assertStringContainsString('/~preview/' . self::SAMPLE_TOKEN . '/about', $url);
        self::assertStringContainsString('keep=1', $url);
        self::assertStringNotContainsString('weline_preview_token=', $url);
        self::assertStringNotContainsString('editor_mode=', $url);
    }

    public function testPrefixStorefrontUrlAlsoStripsSiteMount(): void
    {
        $url = ThemeLivePreviewPathMount::prefixStorefrontUrl(
            'https://p05113ef3.test.weline.com/~site/grocery/CNY/terms',
            self::SAMPLE_TOKEN,
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/~preview/' . self::SAMPLE_TOKEN . '/CNY/terms',
            $url
        );
    }

    public function testJoinPreviewPathUnwrapsInvertedSiteThenPreviewStack(): void
    {
        // Language switcher bug shape: website mount + nested preview path.
        $url = ThemeLivePreviewPathMount::joinPreviewPath(
            'https://p05113ef3.test.weline.com/~site/grocery/~preview/' . self::SAMPLE_TOKEN . '/CNY/terms',
            self::SAMPLE_TOKEN,
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/~preview/' . self::SAMPLE_TOKEN . '/CNY/terms',
            $url
        );
        self::assertStringNotContainsString('/~site/', $url);
    }

    public function testRehydrateSiteMountIntoRouting(): void
    {
        self::assertSame(
            '/~site/grocery/CNY/terms',
            ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/CNY/terms', 'grocery')
        );
        self::assertSame(
            '/~site/daocharms/about?keep=1',
            ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/about?keep=1', 'daocharms')
        );
        // Already mounted — no double prefix.
        self::assertSame(
            '/~site/grocery/about',
            ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/~site/grocery/about', 'grocery')
        );
        // Default website stays bare on project Host.
        self::assertSame(
            '/CNY/terms',
            ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/CNY/terms', 'default')
        );
        self::assertSame(
            '/CNY/terms',
            ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/CNY/terms', null)
        );
    }

    public function testParseRejectsInvalidToken(): void
    {
        self::assertNull(ThemeLivePreviewPathMount::parseFromUri('/~preview/not-a-token/about'));
        self::assertNull(ThemeLivePreviewPathMount::parseFromUri('/~site/grocery/about'));
    }

    public function testConflictsWithDomainSubPath(): void
    {
        self::assertTrue(ThemeLivePreviewPathMount::conflictsWithDomainSubPath('/~preview'));
        self::assertTrue(ThemeLivePreviewPathMount::conflictsWithDomainSubPath('/~preview/x'));
        self::assertFalse(ThemeLivePreviewPathMount::conflictsWithDomainSubPath('/shop'));
    }
}
