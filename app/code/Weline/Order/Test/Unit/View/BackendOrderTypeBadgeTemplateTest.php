<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderTypeBadgeTemplateTest extends TestCase
{
    public function testBackendOrderListRendersTypeBadgeWithDataTone(): void
    {
        $template = dirname(__DIR__, 3) . '/view/templates/Backend/Order/index.phtml';
        self::assertFileExists($template);
        $source = (string) file_get_contents($template);

        self::assertStringContainsString('data-testid="order-type-badge"', $source);
        self::assertMatchesRegularExpression(
            '/data-testid="order-type-badge"[^>]*>|data-testid="order-type-badge"/',
            $source
        );
        self::assertStringContainsString('data-tone="<?= htmlspecialchars($rowTypeTone) ?>"', $source);
        self::assertStringContainsString('data-order-type-code="<?= htmlspecialchars($rowOrderType) ?>"', $source);
        self::assertStringContainsString('resolveLabel', $source);
        self::assertStringContainsString('resolveBadgeTone', $source);
        self::assertDoesNotMatchRegularExpression(
            '/data-testid="order-type-badge"[^>]*bg-<\?=/',
            $source
        );
    }

    public function testBackendOrderViewRendersTypeHeaderBadgeWithDataTone(): void
    {
        $template = dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml';
        self::assertFileExists($template);
        $source = (string) file_get_contents($template);

        self::assertStringContainsString('data-testid="order-type-header-badge"', $source);
        self::assertStringContainsString('data-tone="<?= htmlspecialchars($orderTypeTone) ?>"', $source);
        self::assertStringContainsString('data-order-type-code="<?= htmlspecialchars($orderTypeCode) ?>"', $source);
        self::assertDoesNotMatchRegularExpression(
            '/data-testid="order-type-header-badge"[^>]*bg-<\?=/',
            $source
        );
    }
}
