<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class CheckoutLayoutRouteContractTest extends TestCase
{
    public function testNestedCheckoutSuccessLayoutExistsAndControllerDoesNotForceLayoutType(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/view/theme/frontend/layouts/checkout/success/default.phtml');
        self::assertFileExists($root . '/view/theme/frontend/layouts/checkout/failure/default.phtml');
        self::assertDirectoryDoesNotExist($root . '/view/theme/frontend/layouts/checkout_success');
        self::assertDirectoryDoesNotExist($root . '/view/theme/frontend/layouts/checkout_failure');
        self::assertDirectoryDoesNotExist($root . '/view/theme/frontend/layouts/checkout_failer');
        self::assertFileExists($root . '/Controller/Failure.php');
        self::assertFileExists($root . '/view/frontend/checkout/failure.phtml');

        $successLayout = (string)file_get_contents($root . '/view/theme/frontend/layouts/checkout/success/default.phtml');
        self::assertStringContainsString('data-layout="checkout/success"', $successLayout);

        $failureLayout = (string)file_get_contents($root . '/view/theme/frontend/layouts/checkout/failure/default.phtml');
        self::assertStringContainsString('data-layout="checkout/failure"', $failureLayout);
        self::assertStringContainsString('checkout-failure-content--passthrough', $failureLayout);

        $resolve = (string)file_get_contents($root . '/Observer/LayoutResolveObserver.php');
        self::assertStringContainsString("requestPath === 'checkout/success'", $resolve);
        self::assertStringContainsString("requestPath === 'checkout/failure'", $resolve);
        self::assertStringContainsString("layout_path', \$layoutPath", $resolve);

        $success = (string)file_get_contents($root . '/Controller/Success.php');
        self::assertStringNotContainsString('layoutType =', $success);
        self::assertStringContainsString('isThemeEditorCanvasRequest', $success);
        self::assertStringContainsString('renderEditorPreviewShell', $success);

        $failure = (string)file_get_contents($root . '/Controller/Failure.php');
        self::assertStringNotContainsString('layoutType =', $failure);
        self::assertStringContainsString("frontend/checkout/failure.phtml", $failure);
        self::assertStringContainsString('isThemeEditorCanvasRequest', $failure);

        $events = (string)file_get_contents($root . '/etc/event.xml');
        self::assertStringContainsString('Weline_Checkout::layout_resolve', $events);
        self::assertStringNotContainsString('layout_preview_sample', $events);
        self::assertFileDoesNotExist($root . '/Observer/LayoutPreviewSampleObserver.php');
    }
}
