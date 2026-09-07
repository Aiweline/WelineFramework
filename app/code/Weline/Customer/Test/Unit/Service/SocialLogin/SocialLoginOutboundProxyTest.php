<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\SocialLoginOutboundProxy;

final class SocialLoginOutboundProxyTest extends TestCase
{
    public function testNormalizeStripsUserinfoIntoUserpwd(): void
    {
        $resolved = SocialLoginOutboundProxy::normalize('http://user:pass@127.0.0.1:7892', 'http');
        self::assertSame('http://127.0.0.1:7892', $resolved['proxy']);
        self::assertSame('http', $resolved['type']);
        self::assertSame('user:pass', $resolved['userpwd']);
    }

    public function testNormalizeDetectsSocksScheme(): void
    {
        $resolved = SocialLoginOutboundProxy::normalize('socks5://127.0.0.1:7890', 'http');
        self::assertSame('socks5://127.0.0.1:7890', $resolved['proxy']);
        self::assertSame('socks5', $resolved['type']);
    }

    public function testEmptyProxyReturnsEmpty(): void
    {
        $resolved = SocialLoginOutboundProxy::normalize('  ', 'socks5');
        self::assertSame('', $resolved['proxy']);
        self::assertSame('http', $resolved['type']);
        self::assertSame('', $resolved['userpwd']);
    }
}
