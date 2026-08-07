<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;

final class GatewayHostBootIdentityTest extends TestCase
{
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
