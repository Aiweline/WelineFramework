<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Express 确认扣款前必须把「重报价后的最新金额」同步进支付请求快照。
 *
 * 背景（2026-09-26 实测）：回跳 review 会重报价（运费/税/优惠），订单总额随之变化，
 * 但 payment_transaction.request_data 仍是下单时的旧金额。若不同步：
 *   1. provider 侧 patch 的金额分解会按旧小计/旧运费拼，与目标 value 不守恒；
 *   2. 其它读 request_data.totals 的展示会显示旧小计。
 */
final class ExpressConfirmCaptureAmountSyncContractTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
    }

    public function testConfirmPassesAmendedTotalsIntoMarkConfirmCapture(): void
    {
        // 权威金额只能来自 amendUnpaidOrder 的重报价结果
        self::assertStringContainsString('$amended = $this->amendUnpaidOrder($orderUuid, $address, [', $this->src);
        self::assertMatchesRegularExpression(
            '/\$this->markConfirmCapture\(\s*\$transactionNo,\s*\$grandMinor,\s*\(string\) \(\$order\[\'currency\'\] \?\? \'CNY\'\),\s*\\\is_array\(\$amended\[\'totals\'\] \?\? null\) \? \$amended\[\'totals\'\] : \[\],\s*\);/',
            $this->src,
            'confirm 必须把 amend 后的 totals 传给 markConfirmCapture',
        );
    }

    public function testMarkConfirmCaptureRewritesStaleAmountsInRequestSnapshot(): void
    {
        self::assertMatchesRegularExpression(
            '/private function markConfirmCapture\(string \$transactionNo, int \$grandMinor, string \$currency, array \$totals = \[\]\): void/',
            $this->src,
        );
        // 合计与金额必须覆盖为最新值
        self::assertStringContainsString("\$request['amount_minor'] = \$grandMinor;", $this->src);
        self::assertStringContainsString("\$request['amount'] = \$grandMinor / 100.0;", $this->src);
        // totals 必须合并写回（保留原有键，仅覆盖本次重报价给出的键）
        self::assertMatchesRegularExpression(
            '/\$request\[\'totals\'\] = array_replace\(\s*\$existingTotals,/',
            $this->src,
            'totals 必须合并写回，不能整体丢弃原有键',
        );
        // patch 目标金额仍必须存在（provider 侧据此 patch 支付商订单金额）
        self::assertStringContainsString("\$request['patch_amount_minor'] = \$grandMinor;", $this->src);
        // 确认扣款必须丢弃创建时的过期 amount_breakdown，并按 totals 回写 discount_lines
        self::assertStringContainsString("unset(\$request['amount_breakdown']);", $this->src);
        self::assertStringContainsString("\$request['discount_lines'] = [[", $this->src);
    }
}
