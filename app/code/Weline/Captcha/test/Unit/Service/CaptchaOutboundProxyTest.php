<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Service\CaptchaOutboundProxy;

final class CaptchaOutboundProxyTest extends TestCase
{
    public function testNormalizeStripsUserinfoIntoUserpwd(): void
    {
        $resolved = CaptchaOutboundProxy::normalize('http://user:pass@127.0.0.1:7892', 'http');
        self::assertSame('http://127.0.0.1:7892', $resolved['proxy']);
        self::assertSame('http', $resolved['type']);
        self::assertSame('user:pass', $resolved['userpwd']);
    }

    public function testNormalizeDetectsSocksScheme(): void
    {
        $resolved = CaptchaOutboundProxy::normalize('socks5://127.0.0.1:7890', 'http');
        self::assertSame('socks5://127.0.0.1:7890', $resolved['proxy']);
        self::assertSame('socks5', $resolved['type']);
    }

    public function testEmptyProxyReturnsEmpty(): void
    {
        $resolved = CaptchaOutboundProxy::normalize('  ', 'socks5');
        self::assertSame('', $resolved['proxy']);
        self::assertSame('http', $resolved['type']);
        self::assertSame('', $resolved['userpwd']);
    }

    public function testSharedSocialLoginProxyConfigKeysAreDeclared(): void
    {
        self::assertSame('captcha/http/proxy', CaptchaOutboundProxy::CONFIG_KEY_PROXY);
        self::assertSame('customer/social_login/http_proxy', CaptchaOutboundProxy::SHARED_SOCIAL_PROXY_KEY);
    }
}
