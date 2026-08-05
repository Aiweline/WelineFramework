<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\FrozenClock;
use Weline\Seo\Service\OptimizationTiming;

final class OptimizationTimingTest extends TestCase
{
    public function testFrozenClockDrivesAnalyzeEvaluateExpiryAndCooldownWindows(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-06-01 15:30:00', new \DateTimeZone('Asia/Shanghai')));
        $timing = new OptimizationTiming($clock);

        self::assertSame([
            'start' => '2026-05-18 07:30:00',
            'end' => '2026-06-01 07:30:00',
        ], $timing->analysisWindow(14));

        $window = $timing->observationWindow(7, 28);
        self::assertSame('2026-06-01 07:30:00', $window['applied_at']);
        self::assertSame('2026-06-08 07:30:00', $window['evaluate_after']);
        self::assertSame('2026-06-29 07:30:00', $window['expires_at']);
        self::assertSame('2026-06-15 07:30:00', $timing->cooldownUntil(14));
        self::assertTrue($timing->isFuture($window['evaluate_after']));
        self::assertFalse($timing->isExpired($window['expires_at']));

        $clock->advance('+7 days');
        self::assertFalse($timing->isFuture($window['evaluate_after']));
        self::assertFalse($timing->isExpired($window['expires_at']));

        $clock->advance('+21 days');
        self::assertTrue($timing->isExpired($window['expires_at']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUsesTheAcceptanceEnvironmentClockWhenBothGatesAreValid(): void
    {
        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_TEST_FROZEN_NOW=2026-08-01 12:34:56+08:00');

        self::assertSame(
            '2026-08-01 04:34:56 UTC',
            (new OptimizationTiming())->now()->format('Y-m-d H:i:s T')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExplicitClockTakesPriorityOverTheAcceptanceEnvironment(): void
    {
        \putenv('WELINE_SEO_ACCEPTANCE_MODE=1');
        \putenv('WELINE_SEO_TEST_FROZEN_NOW=2040-01-02 03:04:05+00:00');
        $clock = new FrozenClock(new \DateTimeImmutable('2026-06-01 07:30:00+00:00'));

        self::assertSame(
            '2026-06-01 07:30:00 UTC',
            (new OptimizationTiming($clock))->now()->format('Y-m-d H:i:s T')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailsClosedToSystemTimeForPartialOrInvalidAcceptanceConfiguration(): void
    {
        $cases = [
            [false, '2040-01-02 03:04:05+00:00'],
            ['1', false],
            ['true', '2040-01-02 03:04:05+00:00'],
            ['1', 'not-a-date'],
        ];

        foreach ($cases as [$acceptanceMode, $frozenNow]) {
            \putenv($acceptanceMode === false
                ? 'WELINE_SEO_ACCEPTANCE_MODE'
                : 'WELINE_SEO_ACCEPTANCE_MODE=' . $acceptanceMode);
            \putenv($frozenNow === false
                ? 'WELINE_SEO_TEST_FROZEN_NOW'
                : 'WELINE_SEO_TEST_FROZEN_NOW=' . $frozenNow);

            $before = \time() - 1;
            $actual = (new OptimizationTiming())->now();
            $after = \time() + 1;

            self::assertGreaterThanOrEqual($before, $actual->getTimestamp());
            self::assertLessThanOrEqual($after, $actual->getTimestamp());
            self::assertSame('UTC', $actual->getTimezone()->getName());
        }
    }
}
