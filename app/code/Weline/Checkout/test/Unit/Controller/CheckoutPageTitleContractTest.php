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
            (string)file_get_contents($controllerRoot . '/SuccessPage.php'),
            (string)file_get_contents($controllerRoot . '/Frontend/Checkout.php'),
        ];

        foreach ($successSources as $source) {
            self::assertStringContainsString("\$this->request->setGet('theme_page_title', (string)__('结账成功'));", $source);
            self::assertStringContainsString("\$this->assign('page_title', __('结账成功'));", $source);
            self::assertStringContainsString("\$this->assign('title', __('结账成功'));", $source);
        }
    }
}
