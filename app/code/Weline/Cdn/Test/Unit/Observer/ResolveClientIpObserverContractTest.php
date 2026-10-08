<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Observer\ResolveClientIpObserver;

final class ResolveClientIpObserverContractTest extends TestCase
{
    public function testCloudflareResolvesCfConnectingIp(): void
    {
        $adapter = new Cloudflare();
        self::assertSame(
            '203.0.113.77',
            $adapter->resolveClientIpFromHeaders([
                'cf-connecting-ip' => '203.0.113.77',
                'x-forwarded-for' => '203.0.113.77, 104.22.83.24',
            ]),
        );
    }

    public function testCloudflareReturnsNullWithoutVendorHeader(): void
    {
        $adapter = new Cloudflare();
        self::assertNull($adapter->resolveClientIpFromHeaders([
            'x-forwarded-for' => '203.0.113.77, 104.22.83.24',
        ]));
    }

    public function testCloudflarePrefersCfConnectingIpOverTrueClientIp(): void
    {
        $adapter = new Cloudflare();
        self::assertSame(
            '203.0.113.1',
            $adapter->resolveClientIpFromHeaders([
                'cf-connecting-ip' => '203.0.113.1',
                'true-client-ip' => '203.0.113.2',
            ]),
        );
    }

    public function testObserverIgnoresWhenTrustedProxyFalse(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Cdn/Observer/ResolveClientIpObserver.php',
        );
        self::assertStringContainsString("getData('trusted_proxy')", $src);
        self::assertStringContainsString('resolveClientIpFromHeaders', $src);
        self::assertStringContainsString("setData('resolved_by'", $src);
    }

    public function testEventXmlRegistersServerOwnedResolveClientIp(): void
    {
        $xml = (string)\file_get_contents(BP . '/app/code/Weline/Cdn/etc/event.xml');
        self::assertStringContainsString(
            'Weline_Server::security::resolve_client_ip',
            $xml,
        );
        self::assertStringContainsString(
            'Weline\\Cdn\\Observer\\ResolveClientIpObserver',
            $xml,
        );
    }

    public function testAdapterInterfaceDeclaresResolveClientIpFromHeaders(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Cdn/Api/AdapterInterface.php',
        );
        self::assertStringContainsString(
            'function resolveClientIpFromHeaders(array $headers): ?string',
            $src,
        );
    }
}
