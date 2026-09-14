<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderRefundOutcomeChipContractTest extends TestCase
{
    public function testRefundHistoryUsesOutcomeChipsNotRawSucceededCode(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = (string)file_get_contents($root . '/view/templates/Backend/Order/panel/refund.phtml');
        $flow = (string)file_get_contents($root . '/view/templates/Backend/Order/partial/status-flow.phtml');
        $chip = (string)file_get_contents($root . '/view/templates/Backend/Order/partial/outcome-chip.phtml');

        self::assertStringContainsString('BackendOrderOutcomeChip', $tpl);
        self::assertStringContainsString('partial/outcome-chip.phtml', $tpl);
        self::assertStringContainsString('order-edit-refund-status', $tpl);
        self::assertStringContainsString('order-edit-refund-customer-view', $tpl);
        self::assertStringNotContainsString(
            "\$escape((\$row['status'] ?? '') . ((\$row['channel_status'] ?? '') !== '' ? ' / ' . \$row['channel_status'] : ''))",
            $tpl,
        );

        self::assertStringContainsString('BackendOrderOutcomeChip', $flow);
        self::assertStringContainsString('partial/outcome-chip.phtml', $flow);
        self::assertStringContainsString('check-circle', $chip);
        self::assertStringContainsString('data-tone=', $chip);
    }
}
