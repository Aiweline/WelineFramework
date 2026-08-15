<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceValidation;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Auth\Device\IssuedRememberedDeviceCredential;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialValidation;

final class AuthenticatedDeviceDtoTest extends TestCase
{
    /** @return iterable<string,array{0:callable():object}> */
    public static function invalidSecurityBoundaryValues(): iterable
    {
        yield 'valid device binding without public id' => [
            static fn(): object => AuthenticatedDeviceValidation::valid(''),
        ];
        yield 'issued credential without raw token' => [
            static fn(): object => new IssuedRememberedDeviceCredential('', 'device', time() + 60),
        ];
        yield 'issued credential without public device id' => [
            static fn(): object => new IssuedRememberedDeviceCredential('token', '', time() + 60),
        ];
        yield 'valid remembered credential without principal' => [
            static fn(): object => RememberedDeviceCredentialValidation::valid('', 'device', time() + 60),
        ];
        yield 'remembered login without public device id' => [
            static fn(): object => AuthenticatedLoginContext::remembered(''),
        ];
        yield 'unknown login source' => [
            static fn(): object => new AuthenticatedLoginContext('untrusted-source'),
        ];
        yield 'device context with expired timestamp' => [
            static fn(): object => new AuthenticatedDeviceContext('frontend', '7', 'session', 0),
        ];
    }

    #[DataProvider('invalidSecurityBoundaryValues')]
    public function testRejectsIncompleteSecurityBoundaryValues(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }
}
