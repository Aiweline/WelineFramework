<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxTlsSessionResumptionVerifier;

/**
 * p95 是性能 SLO，不是发布硬门禁（2026-10-25 线上 33h 停站的触发点）。
 */
final class ManagedNginxTlsLatencyGateTest extends TestCase
{
    public function testLatencyAtOrBelowSloPasses(): void
    {
        self::assertTrue(ManagedNginxTlsSessionResumptionVerifier::isLatencyWithinSlo(1));
        self::assertTrue(ManagedNginxTlsSessionResumptionVerifier::isLatencyWithinSlo(49_999));
        self::assertTrue(ManagedNginxTlsSessionResumptionVerifier::isLatencyWithinSlo(50_000));
        self::assertSame(
            'passed',
            ManagedNginxTlsSessionResumptionVerifier::latencyGateVerdict(50_000)['state'],
        );
    }

    public function testLatencyAboveSloIsObservationOnly(): void
    {
        self::assertFalse(ManagedNginxTlsSessionResumptionVerifier::isLatencyWithinSlo(50_001));
        $verdict = ManagedNginxTlsSessionResumptionVerifier::latencyGateVerdict(50_001);
        self::assertSame('exceeded', $verdict['state']);
        self::assertSame(50_000, $verdict['slo_us']);
    }

    public function testUnmeasuredLatencyIsStillInvalid(): void
    {
        self::assertFalse(ManagedNginxTlsSessionResumptionVerifier::isLatencyWithinSlo(0));
        self::assertSame(
            'exceeded',
            ManagedNginxTlsSessionResumptionVerifier::latencyGateVerdict(0)['state'],
        );
    }
}
