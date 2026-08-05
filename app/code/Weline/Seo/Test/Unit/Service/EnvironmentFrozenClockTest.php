<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\EnvironmentFrozenClock;

final class EnvironmentFrozenClockTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatesAnImmutableUtcClockOnlyWhenBothAcceptanceGatesAreValid(): void
    {
        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_TEST_FROZEN_NOW=2026-08-01 12:34:56+08:00');

        $reflection = new \ReflectionClass(EnvironmentFrozenClock::class);
        self::assertTrue($reflection->isInstantiable());

        $providerClock = new EnvironmentFrozenClock();
        $clock = EnvironmentFrozenClock::fromEnvironment();

        self::assertInstanceOf(EnvironmentFrozenClock::class, $clock);
        self::assertSame('2026-08-01 04:34:56', $providerClock->now()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 04:34:56', $clock->now()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());

        \putenv('WELINE_SEO_TEST_FROZEN_NOW=2035-01-01 00:00:00+00:00');
        self::assertSame('2026-08-01 04:34:56', $providerClock->now()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 04:34:56', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testDirectProviderConstructionFailsClosedWithoutBothGates(): void
    {
        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_TEST_FROZEN_NOW');

        $this->expectException(\LogicException::class);
        new EnvironmentFrozenClock();
    }

    public function testModuleProviderKeepsProductionSystemClockBinding(): void
    {
        $module = require \dirname(__DIR__, 3) . '/etc/module.php';

        self::assertSame(
            \Weline\Seo\Service\SystemClock::class,
            $module['provides'][\Weline\Seo\Service\ClockInterface::class] ?? null
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailsClosedForMissingSingleOrInvalidAcceptanceGates(): void
    {
        \putenv('WELINE_SEO_ACCEPTANCE_MODE');
        \putenv('WELINE_SEO_TEST_FROZEN_NOW=2040-01-02 03:04:05+00:00');
        self::assertNull(EnvironmentFrozenClock::fromEnvironment());

        \putenv('WELINE_SEO_ACCEPTANCE_MODE=true');
        self::assertNull(EnvironmentFrozenClock::fromEnvironment());

        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_TEST_FROZEN_NOW');
        self::assertNull(EnvironmentFrozenClock::fromEnvironment());

        \putenv('WELINE_SEO_TEST_FROZEN_NOW=not-a-date');
        self::assertNull(EnvironmentFrozenClock::fromEnvironment());

        \putenv('WELINE_SEO_TEST_FROZEN_NOW=2026-02-30 00:00:00+00:00');
        self::assertNull(EnvironmentFrozenClock::fromEnvironment());
    }
}
