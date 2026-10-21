<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Index;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\Index\SchemaIndexConfig;

final class SchemaIndexConfigTest extends TestCase
{
    public function testDefaultsKeepAutoApplyEnabled(): void
    {
        $config = new SchemaIndexConfig();

        self::assertTrue($config->autoApply());
        self::assertTrue($config->deferOnUpgrade());
        self::assertSame('01:00-05:00', $config->window());
        self::assertSame(1, $config->maxConcurrent());
        self::assertSame(300, $config->cooldownSeconds());
        self::assertSame(3.0, $config->loadThreshold());
    }

    public function testWindowSupportsDayAndOvernightRanges(): void
    {
        $day = new SchemaIndexConfig(null, null, '01:00-05:00');
        self::assertTrue($day->isWithinWindow(new \DateTimeImmutable('2026-10-21 02:00:00')));
        self::assertFalse($day->isWithinWindow(new \DateTimeImmutable('2026-10-21 12:00:00')));

        $overnight = new SchemaIndexConfig(null, null, '22:00-02:00');
        self::assertTrue($overnight->isWithinWindow(new \DateTimeImmutable('2026-10-21 23:00:00')));
        self::assertTrue($overnight->isWithinWindow(new \DateTimeImmutable('2026-10-21 01:00:00')));
        self::assertFalse($overnight->isWithinWindow(new \DateTimeImmutable('2026-10-21 03:00:00')));

        $always = new SchemaIndexConfig(null, null, '*');
        self::assertTrue($always->isWithinWindow(new \DateTimeImmutable('2026-10-21 12:00:00')));
    }

    public function testLoadThresholdOverride(): void
    {
        $config = new SchemaIndexConfig(null, null, '*', true, 2.0);

        self::assertTrue($config->isLoadAcceptable(1.5));
        self::assertFalse($config->isLoadAcceptable(2.1));
    }
}
