<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Taglib;

use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Context;
use Weline\Meta\Taglib\WMeta;

final class WMetaRequestStateIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        WMeta::resetRequestState();
    }

    protected function tearDown(): void
    {
        WMeta::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testTranslationIdsStayFiberLocalWhenPeerResets(): void
    {
        $observed = [];
        $baseId = 'meta-translate-' . md5('@meta::shared.title');

        $fiberA = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                $observed['a_first'] = self::nextId('@meta::shared.title');
                Fiber::suspend('a-ready');

                $observed['a_second'] = self::nextId('@meta::shared.title');
                WMeta::resetRequestState();
                Fiber::suspend('a-reset');
            } finally {
                WMeta::resetRequestState();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                $observed['b_first'] = self::nextId('@meta::shared.title');
                Fiber::suspend('b-ready');

                $observed['b_second'] = self::nextId('@meta::shared.title');
                Fiber::suspend('b-verified');
            } finally {
                WMeta::resetRequestState();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame($baseId, $observed['a_first']);
        self::assertSame($baseId . '-1', $observed['a_second']);
        self::assertSame($baseId, $observed['b_first']);
        self::assertSame($baseId . '-1', $observed['b_second']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    private static function nextId(string $metaKey): string
    {
        $method = new ReflectionMethod(WMeta::class, 'nextTranslationId');
        $method->setAccessible(true);
        return $method->invoke(null, $metaKey);
    }
}
