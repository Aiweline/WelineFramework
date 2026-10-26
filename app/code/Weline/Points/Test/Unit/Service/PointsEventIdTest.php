<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Points\Service\PointsEventId;

final class PointsEventIdTest extends TestCase
{
    public function testEarnEventIdIsStable(): void
    {
        self::assertSame(
            'points:earn:3:live:10:pay-1',
            PointsEventId::earn(3, 'live', 10, 'pay-1'),
        );
    }

    public function testClawbackEventIdDiffersFromEarn(): void
    {
        $earn = PointsEventId::earn(3, 'live', 10, 'pay-1');
        $clawback = PointsEventId::clawback(3, 'live', 10, 'refund-1');

        self::assertNotSame($earn, $clawback);
        self::assertStringStartsWith('points:clawback:', $clawback);
    }

    public function testUnsafeSegmentsAreNormalized(): void
    {
        self::assertSame(
            'points:earn:0:live:1:a-b-c.d',
            PointsEventId::earn(0, 'live', 1, 'a/b c.d'),
        );
        self::assertSame(
            'points:earn:0:live:1:a_b_c.d',
            PointsEventId::earn(0, 'live', 1, 'a_b_c.d'),
        );
    }
}
