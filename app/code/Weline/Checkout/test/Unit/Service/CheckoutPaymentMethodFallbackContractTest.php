<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 契约：结账壳不得硬编码具体支付方式码兜底。
 *
 * 依据 `Payment/doc/payment-shell.md`「壳列表为空时禁止假按钮兜底」与
 * `shell_provider_business_isomorph`：支付方式是否可用由 Provider 层决定，
 * 壳只消费列表；列表为空时提交空 payment_method，由服务端按资产结算处理。
 */
final class CheckoutPaymentMethodFallbackContractTest extends TestCase
{
    public function testCheckoutTemplateDoesNotHardcodeProviderMethodCode(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertNotSame('', $template);

        self::assertStringNotContainsString(
            "'fake_card'",
            $template,
            '结账模板不得硬编码 fake_card 兜底；不可用的支付方式应由 Provider 列表决定是否出现。',
        );
        self::assertStringNotContainsString(
            'paymentMethod = paymentMethod ||',
            $template,
            '结账模板不得用 || 编造支付方式码。',
        );
    }

    public function testZeroDepositExemptionIsAlignedBetweenGateAndSubmit(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );

        // 提交前门禁与 submitCheckoutPayment 内的豁免必须同款，否则零定金单会被必填拦下。
        self::assertSame(
            2,
            substr_count($template, 'window.WelineB2BCheckoutTob.cashDepositMinor() === 0'),
            '零定金豁免应在提交门禁与提交函数中各出现一次。',
        );
    }

    public function testServerAcceptsEmptyMethodForAssetOnlySettlement(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutOrderPaymentService.php'
        );
        self::assertNotSame('', $source);

        // method code 校验必须落在扣款点之后，而不是函数入口。
        $guardPos = strpos($source, "throw new \\InvalidArgumentException('checkout_payment_method_required')");
        $amountGuardPos = strpos($source, "throw new \\RuntimeException('checkout_payment_amount_invalid')");
        self::assertIsInt($guardPos, 'method code 校验不得被删除。');
        self::assertIsInt($amountGuardPos, '金额校验不得被删除。');
        self::assertGreaterThan(
            $amountGuardPos,
            $guardPos,
            'method code 校验须位于「金额无效」判定之后，即只在真正发起网关扣款时要求。',
        );
    }
}
