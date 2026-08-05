<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Taglib;

use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Context;
use Weline\Widget\Taglib\Widget;

final class WidgetRequestStateIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        Widget::resetRequestState();
    }

    protected function tearDown(): void
    {
        Widget::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testPeerFiberResetDoesNotClearRenderCacheOrRecursionGuard(): void
    {
        $observed = [];

        $fiberA = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::storeState('a');
                Fiber::suspend('a-ready');

                $observed['a_before_reset'] = self::state();
                Widget::resetRequestState();
                $observed['a_after_reset'] = self::state();
                Fiber::suspend('a-reset');
            } finally {
                Widget::resetRequestState();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::storeState('b');
                Fiber::suspend('b-ready');

                $observed['b_after_a_reset'] = self::state();
                Fiber::suspend('b-verified');
            } finally {
                Widget::resetRequestState();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame(self::expectedState('a'), $observed['a_before_reset']);
        self::assertSame(self::emptyState(), $observed['a_after_reset']);
        self::assertSame(self::expectedState('b'), $observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    private static function storeState(string $marker): void
    {
        $method = new ReflectionMethod(Widget::class, 'storeRequestState');
        $method->setAccessible(true);
        $method->invoke(null, self::expectedState($marker));
    }

    /** @return array<string, mixed> */
    private static function state(): array
    {
        $method = new ReflectionMethod(Widget::class, 'requestState');
        $method->setAccessible(true);
        return $method->invoke(null);
    }

    /** @return array<string, mixed> */
    private static function expectedState(string $marker): array
    {
        return [
            'render_cache' => ['cache-' . $marker => '<div>' . $marker . '</div>'],
            'render_depth' => 1,
            'rendering_templates' => ['template-' . $marker => true],
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyState(): array
    {
        return [
            'render_cache' => [],
            'render_depth' => 0,
            'rendering_templates' => [],
        ];
    }
}
