<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Runtime;

use Fiber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Acl\Observer\RouteBefore;
use Weline\Acl\Service\AclService;
use Weline\Framework\Context;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

final class AclRequestCacheIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        RouteBefore::resetRequestCache();
        AclService::resetRequestCache();
    }

    protected function tearDown(): void
    {
        RouteBefore::resetRequestCache();
        AclService::resetRequestCache();
        Context::leave();
        parent::tearDown();
    }

    public function testPeerFiberResetDoesNotClearAclOrSessionRequestCaches(): void
    {
        $observed = [];
        $sessionA = $this->createMock(AuthenticatedSessionInterface::class);
        $sessionB = $this->createMock(AuthenticatedSessionInterface::class);

        $fiberA = new Fiber(function () use (&$observed, $sessionA): void {
            Context::enter(new Context());
            try {
                self::seed('a', $sessionA);
                Fiber::suspend('a-ready');

                $observed['a_before_reset'] = self::snapshot();
                RouteBefore::resetRequestCache();
                AclService::resetRequestCache();
                $observed['a_after_reset'] = self::snapshot();
                Fiber::suspend('a-reset');
            } finally {
                RouteBefore::resetRequestCache();
                AclService::resetRequestCache();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed, $sessionB): void {
            Context::enter(new Context());
            try {
                self::seed('b', $sessionB);
                Fiber::suspend('b-ready');

                $observed['b_after_a_reset'] = self::snapshot();
                Fiber::suspend('b-verified');
            } finally {
                RouteBefore::resetRequestCache();
                AclService::resetRequestCache();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame(self::expectedSnapshot('a', $sessionA), $observed['a_before_reset']);
        self::assertSame(self::emptySnapshot(), $observed['a_after_reset']);
        self::assertSame(self::expectedSnapshot('b', $sessionB), $observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    private static function seed(string $marker, AuthenticatedSessionInterface $session): void
    {
        self::invokeStateMethod(RouteBefore::class, 'storeRequestState', [[
            'backend_whitelist' => ['backend-' . $marker],
            'frontend_whitelist' => ['frontend-' . $marker],
            'backend_session' => $session,
        ]]);
        self::invokeStateMethod(AclService::class, 'storeRequestState', [[
            'route_protected' => ['route-' . $marker => true],
            'route_equivalent_paths' => ['route-' . $marker => ['alias-' . $marker]],
            'role_acl_entries' => [1 => [['source_id' => 'source-' . $marker]]],
        ]]);
    }

    /** @return array<string, mixed> */
    private static function snapshot(): array
    {
        return [
            'route_before' => self::invokeStateMethod(RouteBefore::class, 'requestState'),
            'acl_service' => self::invokeStateMethod(AclService::class, 'requestState'),
        ];
    }

    /** @return array<string, mixed> */
    private static function expectedSnapshot(
        string $marker,
        AuthenticatedSessionInterface&MockObject $session,
    ): array {
        return [
            'route_before' => [
                'backend_whitelist' => ['backend-' . $marker],
                'frontend_whitelist' => ['frontend-' . $marker],
                'backend_session' => $session,
            ],
            'acl_service' => [
                'route_protected' => ['route-' . $marker => true],
                'route_equivalent_paths' => ['route-' . $marker => ['alias-' . $marker]],
                'role_acl_entries' => [1 => [['source_id' => 'source-' . $marker]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function emptySnapshot(): array
    {
        return [
            'route_before' => [
                'backend_whitelist' => false,
                'frontend_whitelist' => false,
                'backend_session' => null,
            ],
            'acl_service' => [
                'route_protected' => [],
                'route_equivalent_paths' => [],
                'role_acl_entries' => [],
            ],
        ];
    }

    private static function invokeStateMethod(string $class, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
