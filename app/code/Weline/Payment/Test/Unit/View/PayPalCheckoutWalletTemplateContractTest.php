<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PayPalCheckoutWalletTemplateContractTest extends TestCase
{
    public function testCheckoutTemplateExposesWalletDataAttributes(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/checkout/paypal.phtml'
        );
        self::assertStringContainsString('data-paypal-sdk-src', $tpl);
        self::assertStringContainsString('data-paypal-google-pay', $tpl);
        self::assertStringContainsString('data-paypal-apple-pay', $tpl);
        self::assertStringContainsString('paypalWalletButtons', $tpl);
        self::assertStringContainsString('PayPalJsSdkUrlBuilder', $tpl);
    }

    public function testCheckoutModuleDoesNotEmbedPayPalSdk(): void
    {
        $checkoutRoot = dirname(__DIR__, 4) . '/Checkout';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($checkoutRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!\in_array($ext, ['php', 'phtml', 'js', 'md'], true)) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if (str_contains($content, 'paypal.com/sdk')) {
                $hits[] = $path;
            }
        }
        self::assertSame([], $hits, 'Checkout must not embed paypal.com/sdk');
    }
}
