<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ResponseStatusResolver;

final class ResponseStatusResolverTest extends TestCase
{
    public function testResolvesJsonErrorCodeWhenNoExplicitHttpStatusExists(): void
    {
        $resolver = new ResponseStatusResolver();

        self::assertSame(401, $resolver->resolve('{"code":"401","msg":"Please log in first"}'));
    }

    public function testPrefersExplicitHttpStatusOverBusinessJsonCode(): void
    {
        $resolver = new ResponseStatusResolver();

        self::assertSame(200, $resolver->resolve('{"code":"401","msg":"Please log in first"}', 200, true));
    }

    public function testIgnoresImplicitDefaultStatusAndKeepsLegacyJsonFallback(): void
    {
        $resolver = new ResponseStatusResolver();

        self::assertSame(401, $resolver->resolve('{"code":"401","msg":"Please log in first"}', 200, false));
    }

    public function testReturnsSuccessForNonJsonBodies(): void
    {
        $resolver = new ResponseStatusResolver();

        self::assertSame(200, $resolver->resolve('<html>ok</html>'));
    }
}
