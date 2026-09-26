<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 回跳 resume 上下文契约：dispatcher 必须把「支付商侧订单快照」交给 provider。
 *
 * 背景（2026-09-26 实测）：Express 回跳 review 会重报价改运费，订单总额随之变化；
 * provider 需要读支付商侧当前金额才能判断是否需要 patch。该快照存在
 * `payment_transaction.response_data`，而 resume 上下文原先只用 `request_data` 组装，
 * provider 读不到 → 判定无需 patch → 按旧金额 capture（订单 204/206：总额 442.97、实扣 323.53）。
 *
 * 两端必须同时成立，缺一端即回归：dispatcher 负责注入，provider 负责读取。
 */
final class PaymentBrowserReturnResumeContextContractTest extends TestCase
{
    private function dispatcherSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php');
    }

    private function payPalProviderSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php'
        );
    }

    public function testDispatcherInjectsStoredProviderPayloadIntoResumeContext(): void
    {
        $src = $this->dispatcherSource();

        // 快照来源必须是 transaction 的 response_data（provider 侧订单 JSON 存这里）
        self::assertStringContainsString('$responseData = $transaction->getResponseData();', $src);
        self::assertStringContainsString("is_array(\$responseData['payload'] ?? null)", $src);
        // 且必须真的放进 resume 上下文
        self::assertMatchesRegularExpression(
            "/'payload'\\s*=>\\s*\\\$providerPayload\\s*,/",
            $src,
            'dispatcher 必须把支付商订单快照注入 resume 上下文，否则 provider 读不到支付商侧金额'
        );
        // resume 上下文仍以 request_data 为底，注入属于叠加而非替换
        self::assertMatchesRegularExpression(
            '/\\$resumeContext\\s*=\\s*array_replace\\(\\$requestData,\\s*\\[/',
            $src
        );
    }

    public function testProviderReadsPresentmentAmountFromContextPayload(): void
    {
        $src = $this->payPalProviderSource();

        // provider 侧读取支付商金额的位置必须与 dispatcher 注入的键一致
        self::assertStringContainsString(
            "\$payload = is_array(\$context['payload'] ?? null) ? \$context['payload'] : [];",
            $src
        );
        self::assertStringContainsString("\$payload['order']['purchase_units'][0]", $src);

        // 未知支付商金额时不得跳过 patch（否则静默按旧金额扣款）
        self::assertStringContainsString('$presentmentKnown = $presentment[\'minor\'] > 0;', $src);
        self::assertMatchesRegularExpression(
            '/\\$needsPatch\\s*=\\s*!\\$presentmentKnown/',
            $src,
            '读不到支付商侧金额时必须视为需要 patch'
        );
    }
}
