<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Bare unified Return URL: DEV stays on shell landing; production redirects home.
 */
final class PaymentBrowserReturnLandingContractTest extends TestCase
{
    public function testEmptyDispatchRendersLandingInDevAndHomeInProduction(): void
    {
        $dispatcher = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php');
        self::assertStringContainsString('isProductionLive()', $dispatcher);
        self::assertStringContainsString("'redirect_path' => '/'", $dispatcher);
        self::assertStringContainsString("'render' => true", $dispatcher);
        self::assertStringContainsString("'template' => 'browser-return'", $dispatcher);
        self::assertStringContainsString('开发环境提示', $dispatcher);
        self::assertStringContainsString('生产环境空参访问会跳转首页', $dispatcher);

        $callback = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Callback.php');
        self::assertStringContainsString("!empty(\$dispatched['render'])", $callback);
        self::assertStringContainsString('browser_return_message', $callback);
        self::assertStringContainsString("\$this->layoutType = null", $callback);
        self::assertStringContainsString('@Cdn cache=false', $callback);

        $checkout = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Checkout.php');
        self::assertStringContainsString("return \$this->redirect('/');", $checkout);
        self::assertStringContainsString('isProductionLive()', $checkout);
        self::assertStringContainsString('payment_return_empty', $checkout);
        self::assertStringContainsString("\$this->layoutType = 'checkout'", $checkout);

        $landing = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/Callback/browser-return.phtml');
        self::assertStringContainsString('<!DOCTYPE html>', $landing);
        self::assertStringContainsString('weline-payment-return', $landing);
        self::assertStringContainsString('<lang>', $landing);
        self::assertStringContainsString('min-height: 100vh', $landing);
        self::assertStringContainsString('开发环境提示', $landing);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/templates/Frontend/Callback/browser-return.phtml');
    }

    public function testOAuthSuccessUsesMessageManagerSuccessApi(): void
    {
        $dispatcher = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php');
        self::assertStringContainsString('MessageManager::success(', $dispatcher);
        self::assertStringNotContainsString('MessageManager::add_success(', $dispatcher);
        self::assertStringContainsString('MessageManager::add_error(', $dispatcher);
    }
}
