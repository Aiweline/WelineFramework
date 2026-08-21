<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CanonicalClientIdentity;

final class CanonicalClientIdentityTrustedProxyTest extends TestCase
{
    public function testProxyRestoredClientIpDoesNotClearTrustedBitWhenForced(): void
    {
        $identity = new CanonicalClientIdentity();
        $headers = [
            'x-forwarded-for' => '203.0.113.50',
            'x-forwarded-proto' => 'https',
            'x-forwarded-port' => '443',
        ];

        $withoutForce = $identity->resolve(
            '203.0.113.50',
            $headers,
            ['127.0.0.0/8', '::1/128'],
            false,
        );
        self::assertFalse($withoutForce['trusted_proxy']);
        self::assertSame('203.0.113.50', $withoutForce['ip']);

        $withForce = $identity->resolve(
            '203.0.113.50',
            $headers,
            ['127.0.0.0/8', '::1/128'],
            true,
        );
        self::assertTrue($withForce['trusted_proxy']);
        self::assertSame('203.0.113.50', $withForce['ip']);
    }

    public function testLoopbackTransportRemainsTrustedWithoutForce(): void
    {
        $identity = new CanonicalClientIdentity();
        $result = $identity->resolve(
            '127.0.0.1',
            ['x-forwarded-for' => '203.0.113.50'],
            ['127.0.0.0/8'],
            false,
        );

        self::assertTrue($result['trusted_proxy']);
        self::assertSame('203.0.113.50', $result['ip']);
    }
}
