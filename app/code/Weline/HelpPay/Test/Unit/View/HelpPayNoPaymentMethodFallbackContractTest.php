<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 契约：HelpPay 壳不得硬编码具体支付方式码，也不得为空列表编造兜底方式。
 *
 * 依据 `Payment/doc/payment-shell.md`「壳列表为空时禁止假按钮兜底」与
 * `shell_provider_business_isomorph`：可用方式由 Provider 列表决定；壳只消费列表。
 * `fake_result` 属于 Fake Provider 自身契约（`FakeProvider` 缺省即 `paid`），
 * 壳不得代写供应商参数。
 */
final class HelpPayNoPaymentMethodFallbackContractTest extends TestCase
{
    /** @return list<string> */
    private function shellSources(): array
    {
        $base = dirname(__DIR__, 3);

        return [
            $base . '/view/templates/frontend/pay/quick.phtml',
            $base . '/Controller/Frontend/QuickPay.php',
            $base . '/Service/HelpPayOrchestrator.php',
            $base . '/view/statics/js/helppay-share.js',
            $base . '/view/statics/frontend/js/helppay-share.js',
        ];
    }

    public function testShellDoesNotHardcodeProviderMethodCode(): void
    {
        foreach ($this->shellSources() as $path) {
            $src = (string)file_get_contents($path);
            self::assertNotSame('', $src, $path . ' 不应为空。');
            self::assertStringNotContainsString(
                'fake_card',
                $src,
                basename($path) . ' 不得硬编码 fake_card；可用方式应由 Provider 列表决定。',
            );
        }
    }

    public function testShellDoesNotWriteProviderSpecificFormValues(): void
    {
        foreach ($this->shellSources() as $path) {
            $src = (string)file_get_contents($path);
            self::assertStringNotContainsString(
                'fake_result',
                $src,
                basename($path) . ' 不得代写 Fake Provider 的 fake_result 参数。',
            );
        }
    }

    public function testQuickPayPreferredMethodComesFromProviderListOnly(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/QuickPay.php'
        );

        self::assertStringContainsString(
            'listMethods(',
            $src,
            'QuickPay 默认支付方式必须来自 Provider 列表。',
        );
        // 列表为空/异常时不得编造方式：返回空串，由调用方按「无可用支付方式」处理。
        self::assertStringContainsString(
            "return '';",
            $src,
            'QuickPay 无可用方式时应返回空串而非兜底 code。',
        );
    }

    public function testQuickPayJsDoesNotInventMethodWhenAttributeMissing(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/helppay-share.js'
        );

        self::assertStringContainsString(
            "getAttribute('data-payment-method')",
            $js,
            'JS 仍须读取服务端下发的 data-payment-method。',
        );
        self::assertStringContainsString(
            'noPaymentMethod',
            $js,
            'JS 在服务端未下发支付方式时须给出明确提示，而不是编造 code。',
        );
    }

    public function testDuplicatedFrontendJsCopiesStayIdentical(): void
    {
        $base = dirname(__DIR__, 3);
        $canonical = (string)file_get_contents($base . '/view/statics/js/helppay-share.js');
        $mirror = (string)file_get_contents($base . '/view/statics/frontend/js/helppay-share.js');

        self::assertSame(
            md5($canonical),
            md5($mirror),
            'helppay-share.js 两份副本必须保持一致，避免改一份漏一份。',
        );
    }
}
