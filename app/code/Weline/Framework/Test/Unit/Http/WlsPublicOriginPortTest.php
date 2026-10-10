<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\WlsPublicOriginPort;

final class WlsPublicOriginPortTest extends TestCase
{
    public function testNonDefaultListenPortFromPublicOrigin(): void
    {
        self::assertSame(
            '9555',
            WlsPublicOriginPort::nonDefaultListenPort([
                'WLS_PUBLIC_ORIGIN' => 'https://p05113ef3.test.weline.com:9555',
            ])
        );
    }

    public function testDefaultHttpsOriginReturnsNull(): void
    {
        self::assertNull(
            WlsPublicOriginPort::nonDefaultListenPort([
                'WLS_PUBLIC_ORIGIN' => 'https://p05113ef3.test.weline.com',
            ])
        );
    }

    public function testEmptyOriginReturnsNull(): void
    {
        self::assertNull(WlsPublicOriginPort::nonDefaultListenPort([]));
    }

    public function testKeepOnlyWhenPureWlsEdgeMatchesPort(): void
    {
        self::assertTrue(WlsPublicOriginPort::keepNonDefaultListenPort('9555', [
            'WLS_EDGE_ADAPTER' => 'wls',
            'WLS_PUBLIC_ORIGIN' => 'https://p05113ef3.test.weline.com:9555',
        ]));
        self::assertFalse(WlsPublicOriginPort::keepNonDefaultListenPort('9555', [
            'WLS_EDGE_ADAPTER' => 'nginx',
            'WLS_PUBLIC_ORIGIN' => 'https://p05113ef3.test.weline.com:9555',
        ]));
        self::assertFalse(WlsPublicOriginPort::keepNonDefaultListenPort('9555', [
            'WLS_PUBLIC_ORIGIN' => 'https://p05113ef3.test.weline.com:9555',
        ]));
    }
}
