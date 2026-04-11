<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\View\Template;

final class TemplateFiberScopedInstanceTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        Template::resetInstance();
    }

    protected function tearDown(): void
    {
        Template::resetInstance();
        Runtime::resetModeCache();
    }

    public function testGetInstanceIsFiberLocalInWlsMode(): void
    {
        $mainInstance = Template::getInstance();

        $fiberA = new \Fiber(static function (): array {
            $instance = Template::getInstance();
            \Fiber::suspend($instance);

            return [$instance, Template::getInstance()];
        });

        $fiberB = new \Fiber(static function (): Template {
            return Template::getInstance();
        });

        $instanceA = $fiberA->start();
        self::assertInstanceOf(Template::class, $instanceA);
        self::assertNotSame($mainInstance, $instanceA);

        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        $instanceB = $fiberB->getReturn();
        self::assertInstanceOf(Template::class, $instanceB);
        self::assertNotSame($mainInstance, $instanceB);
        self::assertNotSame($instanceA, $instanceB);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        [$instanceA1, $instanceA2] = $fiberA->getReturn();
        self::assertSame($instanceA1, $instanceA2);
    }

    public function testResetInstanceOnlyClearsCurrentFiberBucket(): void
    {
        $fiberA = new \Fiber(static function (): array {
            $first = Template::getInstance();
            \Fiber::suspend($first);

            return [$first, Template::getInstance()];
        });

        $fiberB = new \Fiber(static function (): array {
            $first = Template::getInstance();
            Template::resetInstance();
            $second = Template::getInstance();

            return [$first, $second];
        });

        $instanceA = $fiberA->start();
        self::assertInstanceOf(Template::class, $instanceA);

        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        [$instanceB1, $instanceB2] = $fiberB->getReturn();
        self::assertNotSame($instanceB1, $instanceB2);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        [$instanceA1, $instanceA2] = $fiberA->getReturn();
        self::assertSame($instanceA1, $instanceA2);
        self::assertSame($instanceA, $instanceA1);
    }
}
