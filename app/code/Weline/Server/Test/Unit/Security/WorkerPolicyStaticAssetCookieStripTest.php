<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyKernel;

final class WorkerPolicyStaticAssetCookieStripTest extends TestCase
{
    public function testPublicStaticCssPathDropsCookieAndAuthorization(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.201';
        $kernel->clearSecurityBans($ip, false);
        $path = '/Weline/Theme/view/statics/ui/components/weline-choice-selector.css';
        $raw = "GET {$path} HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . "Cookie: session=abc; consent=1\r\n"
            . "Authorization: Bearer secret\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertTrue($decision->allowed, 'static css must be allowed, got: ' . $decision->reason);
        self::assertTrue($kernel->isPublicStaticAssetPath($path));
        self::assertArrayNotHasKey('cookie', $decision->headers);
        self::assertArrayNotHasKey('authorization', $decision->headers);
        self::assertArrayHasKey('host', $decision->headers);
    }

    public function testHtmlPathKeepsCookieForFpcVariant(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.202';
        $kernel->clearSecurityBans($ip, false);
        $raw = "GET / HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . "Cookie: session=keep-me\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        if (!$decision->allowed) {
            self::assertFalse(
                \str_starts_with((string)$decision->reason, 'request_shape'),
                'homepage shape must not reject, got: ' . $decision->reason
            );
            return;
        }
        self::assertFalse($kernel->isPublicStaticAssetPath('/'));
        self::assertSame('session=keep-me', $decision->headers['cookie'] ?? null);
    }
}
