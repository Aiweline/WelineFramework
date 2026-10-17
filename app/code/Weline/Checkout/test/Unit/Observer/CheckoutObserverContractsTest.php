<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCheckoutObserverContractsPass().
 */
final class CheckoutObserverContractsTest extends TestCase
{
    public function testAllCheckoutObserverContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CheckoutLayoutRoute::testNestedCheckoutSuccessLayoutExistsAndControllerDoesNotForceLayoutType' => function () {
            $this->assert_CheckoutLayoutRoute_testNestedCheckoutSuccessLayoutExistsAndControllerDoesNotForceLayoutType();
        },
        ];
    }

private function assert_CheckoutLayoutRoute_testNestedCheckoutSuccessLayoutExistsAndControllerDoesNotForceLayoutType(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout';
    self::assertFileExists($root . '/view/theme/frontend/layouts/checkout/success/default.phtml');
    self::assertFileExists($root . '/view/theme/frontend/layouts/checkout/failure/default.phtml');
    self::assertDirectoryDoesNotExist($root . '/view/theme/frontend/layouts/checkout_success');
    self::assertDirectoryDoesNotExist($root . '/view/theme/frontend/layouts/checkout_failure');
    self::assertDirectoryDoesNotExist($root . '/view/theme/frontend/layouts/checkout_failer');
    self::assertFileExists($root . '/Controller/Failure.php');
    self::assertFileExists($root . '/view/frontend/checkout/failure.phtml');
    $successLayout = (string) file_get_contents($root . '/view/theme/frontend/layouts/checkout/success/default.phtml');
    self::assertStringContainsString('data-layout="checkout/success"', $successLayout);
    self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-end', $successLayout);
    $failureLayout = (string) file_get_contents($root . '/view/theme/frontend/layouts/checkout/failure/default.phtml');
    self::assertStringContainsString('data-layout="checkout/failure"', $failureLayout);
    self::assertStringContainsString('checkout-failure-content--passthrough', $failureLayout);
    self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-end', $failureLayout);
    $resolve = (string) file_get_contents($root . '/Observer/LayoutResolveObserver.php');
    self::assertStringContainsString("requestPath === 'checkout/success'", $resolve);
    self::assertStringContainsString("requestPath === 'checkout/failure'", $resolve);
    self::assertStringContainsString("layout_path', \$layoutPath", $resolve);
    $success = (string) file_get_contents($root . '/Controller/Success.php');
    self::assertStringNotContainsString('layoutType =', $success);
    self::assertStringContainsString('isThemeEditorCanvasRequest', $success);
    self::assertStringContainsString('renderEditorPreviewShell', $success);
    $failure = (string) file_get_contents($root . '/Controller/Failure.php');
    self::assertStringNotContainsString('layoutType =', $failure);
    self::assertStringContainsString("frontend/checkout/failure.phtml", $failure);
    self::assertStringContainsString('isThemeEditorCanvasRequest', $failure);
    $events = (string) file_get_contents($root . '/etc/event.xml');
    self::assertStringContainsString('Weline_Checkout::layout_resolve', $events);
    self::assertStringNotContainsString('layout_preview_sample', $events);
    self::assertFileDoesNotExist($root . '/Observer/LayoutPreviewSampleObserver.php');
}
}
