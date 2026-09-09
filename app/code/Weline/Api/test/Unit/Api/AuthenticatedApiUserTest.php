<?php
declare(strict_types=1);

namespace Weline\Api\test\Unit\Api;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

use PHPUnit\Framework\TestCase;
use Weline\Api\Api\AuthenticatedApiUser;

final class AuthenticatedApiUserTest extends TestCase
{
    public function testVerifiedIdentityHasAnImmutableNamespacedId(): void
    {
        $identity = new AuthenticatedApiUser(31, 12);
        self::assertSame(31, $identity->getUserId());
        self::assertSame(12, $identity->getRoleId());
        self::assertSame('api_user:31', $identity->getIdempotencyScope());
        self::assertTrue((new ReflectionClass($identity))->isReadOnly());
        self::assertNotSame($identity->getIdempotencyScope(), (new AuthenticatedApiUser(32, 12))->getIdempotencyScope());
    }
}
