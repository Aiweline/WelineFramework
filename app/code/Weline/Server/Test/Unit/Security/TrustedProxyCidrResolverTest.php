<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CloudflareTrustedProxyCatalog;
use Weline\Server\Security\TrustedProxyCidrResolver;

final class TrustedProxyCidrResolverTest extends TestCase
{
    public function testForWlsIncludesLoopbackAndCloudflareCatalog(): void
    {
        $cidrs = TrustedProxyCidrResolver::forWls([
            'cdn_trusted_ips' => [
                'enabled' => true,
                'ips' => ['203.0.113.10/32'],
            ],
        ], [
            'trusted_proxy_cidrs' => ['198.51.100.0/24'],
        ]);

        self::assertContains('203.0.113.10/32', $cidrs);
        self::assertContains('198.51.100.0/24', $cidrs);
        self::assertContains('127.0.0.0/8', $cidrs);
        self::assertContains('::1/128', $cidrs);
        self::assertContains(CloudflareTrustedProxyCatalog::cidrs()[0], $cidrs);
    }

    public function testForNginxExcludesLoopbackAndKeepsPublicHops(): void
    {
        $cidrs = TrustedProxyCidrResolver::forNginx([
            'cdn_trusted_ips' => [
                'enabled' => true,
                'ips' => ['203.0.113.10/32'],
            ],
        ]);

        self::assertContains('203.0.113.10/32', $cidrs);
        self::assertContains(CloudflareTrustedProxyCatalog::cidrs()[0], $cidrs);
        self::assertNotContains('127.0.0.0/8', $cidrs);
        self::assertNotContains('::1/128', $cidrs);
    }

    public function testDisabledCdnTrustedIpsDropsCatalogAndCustomIps(): void
    {
        $cidrs = TrustedProxyCidrResolver::forNginx([
            'cdn_trusted_ips' => [
                'enabled' => false,
                'ips' => ['203.0.113.10/32'],
            ],
        ], [
            'trusted_proxy_cidrs' => ['198.51.100.0/24'],
        ]);

        self::assertSame(['198.51.100.0/24'], $cidrs);
    }

    public function testForwardedHeaderTrustAddsLoopbackToPublicHops(): void
    {
        $cidrs = TrustedProxyCidrResolver::forNginxForwardedHeaders([
            '203.0.113.10/32',
            '1.2.3.4; rm -rf /',
        ]);

        self::assertSame(['127.0.0.0/8', '203.0.113.10/32', '::1/128'], $cidrs);
    }

    public function testRejectsUnsafeCidrLiterals(): void
    {
        self::assertFalse(TrustedProxyCidrResolver::isSafeCidrLiteral('1.2.3.4; rm -rf /'));
        self::assertFalse(TrustedProxyCidrResolver::isSafeCidrLiteral('not-an-ip'));
        self::assertTrue(TrustedProxyCidrResolver::isSafeCidrLiteral('104.16.0.0/13'));
    }
}
