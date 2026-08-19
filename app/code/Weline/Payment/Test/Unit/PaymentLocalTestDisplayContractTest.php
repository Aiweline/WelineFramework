<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit;

use PHPUnit\Framework\TestCase;

final class PaymentLocalTestDisplayContractTest extends TestCase
{
    public function testLocalDevelopmentProviderUsesStorefrontSafeTranslatedCopy(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $provider = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_Payment/PaymentProvider/FakeProvider.php'
        );
        $template = (string)file_get_contents(
            $moduleRoot . '/view/templates/Frontend/checkout/fake.phtml'
        );
        $english = (string)file_get_contents($moduleRoot . '/i18n/en_US.csv');
        $chinese = (string)file_get_contents($moduleRoot . '/i18n/zh_Hans_CN.csv');

        self::assertStringContainsString("'title' => (string)__('本地测试支付')", $provider);
        self::assertStringContainsString(
            "'description' => (string)__('仅用于本地开发验证，不会产生真实扣款。')",
            $provider,
        );
        self::assertStringContainsString("<?= __('本地测试支付') ?>", $template);
        self::assertStringContainsString("<?= __('仅用于本地开发验证，不会产生真实扣款。') ?>", $template);
        self::assertStringContainsString('本地测试支付,"Local Test Payment"', $english);
        self::assertStringContainsString(
            '仅用于本地开发验证，不会产生真实扣款。,"For local development testing only. No real charge will be made."',
            $english,
        );
        self::assertStringContainsString('本地测试支付,本地测试支付', $chinese);
        self::assertStringContainsString(
            '仅用于本地开发验证，不会产生真实扣款。,仅用于本地开发验证，不会产生真实扣款。',
            $chinese,
        );
    }
}
