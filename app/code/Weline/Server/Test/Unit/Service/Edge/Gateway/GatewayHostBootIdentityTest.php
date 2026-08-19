<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;

final class GatewayHostBootIdentityTest extends TestCase
{
    public function testDarwinPlatformTokenUsesTheStableBootSessionUuid(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('The macOS boot-session contract is Darwin-specific.');
        }

        $lines = [];
        $exitCode = 1;
        \exec('/usr/sbin/sysctl -n kern.bootsessionuuid', $lines, $exitCode);
        $bootSessionUuid = \strtolower(\trim(\implode("\n", $lines)));

        self::assertSame(0, $exitCode);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/D',
            $bootSessionUuid,
        );
        self::assertSame(
            'darwin-' . $bootSessionUuid,
            GatewayHostBootIdentity::platformToken(),
            'A macOS host boot identity must not depend on the mutable kern.boottime microseconds.',
        );
    }

    public function testValidatedPlatformTokenIsRetainedForProcessLifetime(): void
    {
        $token = GatewayHostBootIdentity::platformToken();
        $identity = new \ReflectionClass(GatewayHostBootIdentity::class);

        // A native lifecycle crash cannot be asserted after it terminates PHP;
        // pin the memoization boundary that prevents repeated FFI/CData teardown.
        self::assertTrue($identity->hasProperty('resolvedPlatformToken'));
        $cached = $identity->getProperty('resolvedPlatformToken')->getValue();

        self::assertSame($token, $cached);
        self::assertSame($token, GatewayHostBootIdentity::platformToken());
    }
}
