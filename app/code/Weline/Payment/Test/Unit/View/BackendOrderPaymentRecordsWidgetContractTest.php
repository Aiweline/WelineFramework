<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderPaymentRecordsWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsOrderPaymentRecordsSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        $tpl = 'Weline_Payment::templates/Backend/widgets/backend-order-payment-records.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/widgets/backend-order-payment-records.phtml');
        self::assertStringContainsString('@widget.code {backend-order-payment-records}', $src);
        self::assertStringContainsString('@widget.slot {backend-order-payment-records}', $src);
        self::assertStringContainsString('"layout_type":"backend-order-view"', $src);
        self::assertStringContainsString('"slot":"backend-order-payment-records"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testHookTemplateDelegatesToPaymentWidget(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Order/backend/order/view/payment-records.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            'Weline_Payment::templates/Backend/widgets/backend-order-payment-records.phtml',
            $source
        );
    }

    public function testWidgetTemplateUsesAttemptService(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/templates/Backend/widgets/backend-order-payment-records.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('BackendOrderPaymentRecordsService', $source);
        self::assertStringContainsString('@widget.default_injections', $source);
        self::assertStringContainsString('data-testid="backend-order-payment-records"', $source);
    }

    public function testWidgetTemplateShowsMethodIconAndFourColorStatusChip(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string)file_get_contents(
            $root . '/view/templates/Backend/widgets/backend-order-payment-records.phtml'
        );
        $chip = (string)file_get_contents(
            $root . '/view/templates/Backend/partials/outcome-chip.phtml'
        );

        self::assertStringContainsString('BackendPaymentOutcomeChip', $source);
        self::assertStringContainsString('partials/outcome-chip.phtml', $source);
        self::assertStringContainsString('payment-record-method-icon', $source);
        self::assertStringContainsString('payment-record-status-chip', $source);
        self::assertStringContainsString('method_icon_url', $source);
        self::assertStringContainsString('method_label', $source);
        self::assertStringContainsString('payment-record-entry', $source);
        self::assertStringContainsString('payment_entry_label', $source);
        self::assertStringContainsString('data-payment-entry=', $source);
        self::assertStringNotContainsString(
            'data-w-background="success"><?= $esc((string)($payment[\'status\'] ?? \'\'))',
            $source
        );

        self::assertStringContainsString('data-tone=', $chip);
        self::assertStringContainsString('check-circle', $chip);
    }
}
