<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Taglib;

use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Acl\Taglib\Acl;
use Weline\Framework\Context;

final class AclRequestStateIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        Acl::resetRequestState();
    }

    protected function tearDown(): void
    {
        Acl::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testPeerFiberResetDoesNotClearAnotherPermissionCache(): void
    {
        $observed = [];

        $fiberA = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::cachePermission('resource-a', true);
                Fiber::suspend('a-ready');

                $observed['a_before_reset'] = self::permissionCache();
                Acl::resetRequestState();
                $observed['a_after_reset'] = self::permissionCache();
                Fiber::suspend('a-reset');
            } finally {
                Acl::resetRequestState();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::cachePermission('resource-b', false);
                Fiber::suspend('b-ready');

                $observed['b_after_a_reset'] = self::permissionCache();
                Fiber::suspend('b-verified');
            } finally {
                Acl::resetRequestState();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame(['resource-a' => true], $observed['a_before_reset']);
        self::assertSame([], $observed['a_after_reset']);
        self::assertSame(['resource-b' => false], $observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    private static function cachePermission(string $source, bool $allowed): void
    {
        $method = new ReflectionMethod(Acl::class, 'cachePermission');
        $method->setAccessible(true);
        $method->invoke(null, $source, $allowed);
    }

    /** @return array<string, bool> */
    private static function permissionCache(): array
    {
        $method = new ReflectionMethod(Acl::class, 'permissionCache');
        $method->setAccessible(true);
        return $method->invoke(null);
    }
}
