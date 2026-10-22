<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;

/**
 * T1 验收：path 级缓存清理统一入口（纯归一化 + 契约形状）。
 *
 * 不触库、不发事件：只钉住「哪些 path 会被当作 storefront path 清理」的判定，
 * 以及方法与目标结构必须存在，防执行器把入口做成全量清理。
 */
final class ThemeStorefrontPathPurgeTest extends TestCase
{
    public function testNormalizesRelativeAndAbsolutePaths(): void
    {
        $result = ThemeStorefrontSlugFpcPublisher::normalizeStorefrontPurgePaths([
            '/products/hanfu',
            'https://example.com/blog/post-1?from=nav#top',
            'policy/privacy',
            '/',
        ]);

        self::assertSame(
            ['products/hanfu', 'blog/post-1', 'policy/privacy'],
            $result['slugs'],
        );
        self::assertTrue($result['homepage']);
        self::assertSame([], $result['rejected']);
        self::assertSame(0, $result['truncated']);
    }

    public function testRejectsTraversalPreviewAndEmptyPaths(): void
    {
        $result = ThemeStorefrontSlugFpcPublisher::normalizeStorefrontPurgePaths([
            '',
            '/../etc/passwd',
            '/~preview/token/page',
            '   ',
            'https://example.com/../x',
            '/keep/me',
        ]);

        self::assertSame(['keep/me'], $result['slugs']);
        self::assertFalse($result['homepage']);
        self::assertNotEmpty($result['rejected']);
    }

    public function testDeduplicatesAndCapsAtTwoHundredPaths(): void
    {
        $paths = ['/a', '/a', 'a/', 'https://example.com/a'];
        for ($i = 0; $i < 205; ++$i) {
            $paths[] = '/cap/' . $i;
        }

        $result = ThemeStorefrontSlugFpcPublisher::normalizeStorefrontPurgePaths($paths);

        self::assertCount(200, $result['slugs']);
        self::assertContains('a', $result['slugs']);
        self::assertSame(6, $result['truncated']);
    }

    public function testPurgeEntryIsPublicAndFailsClosedWithoutTheme(): void
    {
        self::assertTrue(\method_exists(ThemeStorefrontSlugFpcPublisher::class, 'purgeStorefrontPaths'));

        $method = new \ReflectionMethod(ThemeStorefrontSlugFpcPublisher::class, 'purgeStorefrontPaths');
        self::assertTrue($method->isPublic());
        self::assertSame(4, $method->getNumberOfParameters());
        self::assertSame(3, $method->getNumberOfRequiredParameters());

        /** @var ThemeStorefrontSlugFpcPublisher $publisher */
        $publisher = (new \ReflectionClass(ThemeStorefrontSlugFpcPublisher::class))
            ->newInstanceWithoutConstructor();
        $result = $publisher->purgeStorefrontPaths(0, ['/products/x'], []);

        self::assertFalse($result['ok']);
        self::assertSame('no_theme', $result['skipped']);
        self::assertSame(0, $result['target_count']);
    }

    public function testPurgeEntryFailsClosedWithoutPaths(): void
    {
        /** @var ThemeStorefrontSlugFpcPublisher $publisher */
        $publisher = (new \ReflectionClass(ThemeStorefrontSlugFpcPublisher::class))
            ->newInstanceWithoutConstructor();
        $result = $publisher->purgeStorefrontPaths(3, ['/../bad', '~preview'], [
            ['version_owner_scope' => 'website:default', 'store_mode' => 'normal', 'area' => 'frontend'],
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('no_paths', $result['skipped']);
        self::assertSame(0, $result['target_count']);
    }
}
