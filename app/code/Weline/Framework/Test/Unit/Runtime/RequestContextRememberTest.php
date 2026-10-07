<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

final class RequestContextRememberTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('remember-test');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testRememberComputesOncePerRequest(): void
    {
        $calls = 0;
        $a = RequestContext::remember('framework.remember.demo.v1', static function () use (&$calls): string {
            ++$calls;

            return 'once';
        });
        $b = RequestContext::remember('framework.remember.demo.v1', static function () use (&$calls): string {
            ++$calls;

            return 'twice';
        });

        self::assertSame('once', $a);
        self::assertSame('once', $b);
        self::assertSame(1, $calls);
    }
}
