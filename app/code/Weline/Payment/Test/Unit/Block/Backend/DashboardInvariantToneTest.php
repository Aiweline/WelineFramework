<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Block\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Block\Backend\Dashboard;

/**
 * Theme semantic tone levels for payment invariant table badges.
 */
final class DashboardInvariantToneTest extends TestCase
{
    public function testRepairableAnomalyUsesDangerLevel(): void
    {
        $tones = Dashboard::resolveInvariantTones(true, 1);

        self::assertSame('danger', $tones['status']);
        self::assertSame('danger', $tones['count']);
        self::assertSame('warning', $tones['strategy']);
    }

    public function testAlertOnlyAnomalyUsesWarningLevel(): void
    {
        $tones = Dashboard::resolveInvariantTones(false, 6);

        self::assertSame('warning', $tones['status']);
        self::assertSame('warning', $tones['count']);
        self::assertSame('info', $tones['strategy']);
    }

    public function testPassedInvariantUsesSuccessLevel(): void
    {
        $tones = Dashboard::resolveInvariantTones(false, 0);

        self::assertSame('success', $tones['status']);
        self::assertSame('muted', $tones['count']);
        self::assertSame('info', $tones['strategy']);
    }

    public function testPassedRepairableStillMarksStrategyAsWarning(): void
    {
        $tones = Dashboard::resolveInvariantTones(true, 0);

        self::assertSame('success', $tones['status']);
        self::assertSame('muted', $tones['count']);
        self::assertSame('warning', $tones['strategy']);
    }

    public function testDashboardTemplateBindsThemeToneAttributes(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Backend/Dashboard/index.phtml'
        );
        self::assertIsString($template);
        self::assertStringContainsString('\\Weline\\Payment\\Block\\Backend\\Dashboard::resolveInvariantTones', $template);
        self::assertStringContainsString('data-tone="<?= $escape($tones[\'status\']) ?>"', $template);
        self::assertStringContainsString('data-tone="<?= $escape($tones[\'strategy\']) ?>"', $template);
        self::assertStringContainsString('data-tone="<?= $escape($tones[\'count\']) ?>"', $template);
        self::assertStringContainsString('data-testid="payment-invariant-status"', $template);
        self::assertMatchesRegularExpression(
            '/data-tone="<\?= \$escape\(\$tones\[\'status\'\]\) \?>"\s+data-testid="payment-invariant-status"/',
            $template
        );
        self::assertDoesNotMatchRegularExpression(
            '/data-w-background="[^"]+"\s+data-testid="payment-invariant-status"/',
            $template
        );
    }
}
