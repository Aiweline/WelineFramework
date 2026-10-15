<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\CookieScope;
use Weline\RecentlyViewed\Service\RecentlyViewedSessionStore;

/**
 * Shared project hosts (Hanfu apex + DaoCharms /daocharms) must not bleed MRU.
 */
final class RecentlyViewedSessionStoreIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        CookieScope::setPolicyResolverOverride(null);
        CookieScope::resetRequestState();
        $_COOKIE = [];
        parent::tearDown();
    }

    public function testSuperglobalFallbackIgnoresSiblingWebsiteSuffix(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'test-w0',
        ]);
        CookieScope::resetRequestState();

        $_COOKIE = [
            'weline_recently_viewed_w158' => json_encode([22, 79, 85], JSON_UNESCAPED_UNICODE),
        ];

        $store = new RecentlyViewedSessionStore();
        self::assertSame([], $store->listIds(), 'Hanfu scope must ignore DaoCharms _w158 MRU cookie');
    }

    public function testSuperglobalFallbackReadsCurrentWebsiteWireName(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'test-w0',
        ]);
        CookieScope::resetRequestState();

        $_COOKIE = [
            'weline_recently_viewed_w158' => json_encode([22], JSON_UNESCAPED_UNICODE),
            'weline_recently_viewed_w0' => json_encode([215, 186], JSON_UNESCAPED_UNICODE),
        ];

        $store = new RecentlyViewedSessionStore();
        self::assertSame([215, 186], $store->listIds());
    }

    public function testSourceForbidsSiblingSuffixScan(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RecentlyViewedSessionStore.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('CookieScope::qualifyName', $source);
        self::assertStringNotContainsString("str_starts_with(\$name, \$prefix . '_')", $source);
        self::assertStringContainsString('Never scan sibling website suffixes', $source);
    }
}
