<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutPageTitleContractTest extends TestCase
{
    public function testCheckoutControllersPublishThemeAndSeoTitles(): void
    {
        $controllerRoot = dirname(__DIR__, 3) . '/Controller';
        $checkoutSources = [
            (string)file_get_contents($controllerRoot . '/Index.php'),
            (string)file_get_contents($controllerRoot . '/Frontend/Checkout.php'),
        ];

        foreach ($checkoutSources as $source) {
            self::assertStringContainsString("\$this->request->setGet('theme_page_title', (string)__('结账'));", $source);
            self::assertStringContainsString("\$this->assign('page_title', __('结账'));", $source);
            self::assertStringContainsString("\$this->assign('title', __('结账'));", $source);
        }
    }

    public function testSuccessControllersPublishThemeAndSeoTitles(): void
    {
        $controllerRoot = dirname(__DIR__, 3) . '/Controller';
        $successSources = [
            (string)file_get_contents($controllerRoot . '/Success.php'),
        ];

        foreach ($successSources as $source) {
            self::assertStringContainsString("__('结账成功')", $source);
            self::assertStringContainsString("__('支付已取消')", $source);
            self::assertStringContainsString("\$this->request->setGet('theme_page_title', \$title)", $source);
            self::assertStringContainsString("\$this->assign('page_title', \$title)", $source);
            self::assertStringContainsString("\$this->assign('title', \$title)", $source);
            self::assertStringNotContainsString("__('已取消成功')", $source);
        }

        $legacy = (string)file_get_contents($controllerRoot . '/Frontend/Checkout.php');
        self::assertStringNotContainsString('function successPage', $legacy);
        self::assertFileDoesNotExist($controllerRoot . '/SuccessPage.php');
    }
}
