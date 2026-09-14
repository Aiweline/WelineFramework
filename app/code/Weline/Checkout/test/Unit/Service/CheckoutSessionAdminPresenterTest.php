<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutSessionAdminPresenter;
use Weline\Checkout\Service\CheckoutSessionFaultRecorder;

final class CheckoutSessionAdminPresenterTest extends TestCase
{
    public function testMissingWeightBecomesOperatorCopy(): void
    {
        $p = new CheckoutSessionAdminPresenter();
        $row = $p->present([
            CheckoutSession::schema_fields_ID => 66,
            CheckoutSession::schema_fields_QUOTE_TOKEN => 'qt_e2e_fault_demo',
            CheckoutSession::schema_fields_STATE => CheckoutSession::STATE_QUOTED,
            CheckoutSession::schema_fields_CURRENCY => 'USD',
            CheckoutSession::schema_fields_ERROR_CODE => CheckoutSessionFaultRecorder::CODE_MISSING_WEIGHT,
            CheckoutSession::schema_fields_ERROR_AT => '2026-09-14 08:36:10',
            CheckoutSession::schema_fields_ERROR_SNAPSHOT_JSON => json_encode([
                'error_code' => 'missing_weight',
                'country_code' => 'US',
                'province' => 'NY',
                'city' => 'New York',
                'postal_code' => '10001',
                'empty_message' => '购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。',
                'lines' => [['sku' => 'E2E-MISS-WT', 'product_id' => 321, 'weight_minor' => 0, 'qty' => 1]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        self::assertSame('商品缺少重量', $row['error_label']);
        self::assertSame('选配中', $row['state_label']);
        self::assertSame('美国 纽约州 纽约 10001', $row['destination']);
        self::assertSame('目录没填重量，补上后买家才能算出运费。', $row['operator_hint']);
        self::assertSame('未填写', $row['lines'][0]['weight_label']);
        self::assertTrue($row['has_error']);
        self::assertSame('unknown', $row['checkout_entry']);
        self::assertSame('未标记', $row['checkout_entry_label']);
    }

    public function testCheckoutEntryFromColumn(): void
    {
        $p = new CheckoutSessionAdminPresenter();
        $row = $p->present([
            CheckoutSession::schema_fields_ID => 1,
            CheckoutSession::schema_fields_QUOTE_TOKEN => 'qt_entry',
            CheckoutSession::schema_fields_STATE => CheckoutSession::STATE_QUOTED,
            CheckoutSession::schema_fields_CURRENCY => 'CNY',
            CheckoutSession::schema_fields_CHECKOUT_ENTRY => 'express',
        ]);
        self::assertSame('express', $row['checkout_entry']);
        self::assertSame('快捷支付', $row['checkout_entry_label']);
    }

    public function testHalfKgFormatsWithoutTrailingZeros(): void
    {
        $p = new CheckoutSessionAdminPresenter();
        self::assertSame('0.5 kg', $p->weightLabel(500));
        self::assertSame('1 kg', $p->weightLabel(1000));
    }

    public function testDiagnosticSummaryLabelsAreOperatorReadable(): void
    {
        $p = new CheckoutSessionAdminPresenter();
        self::assertSame('选配中', $p->summaryStateLabel(CheckoutSession::STATE_QUOTED));
        self::assertSame('正在提交', $p->summaryStateLabel(CheckoutSession::STATE_SUBMITTING));
        self::assertSame('已下单', $p->summaryStateLabel(CheckoutSession::STATE_SUBMITTED));
        self::assertSame('已过期', $p->summaryStateLabel('expired'));
        self::assertSame('未知', $p->summaryStateLabel('unknown'));
        self::assertSame('danger', $p->summaryStateTone('expired'));
        self::assertSame('success', $p->summaryStateTone(CheckoutSession::STATE_SUBMITTED));
    }

    public function testExpiryStatusLabelUsesRelativeCopy(): void
    {
        $p = new CheckoutSessionAdminPresenter();
        $now = strtotime('2026-09-14 10:00:00 UTC');
        self::assertSame('已过期', $p->expiryStatusLabel('2026-09-14 09:00:00', $now));
        self::assertSame('还剩 30 分钟', $p->expiryStatusLabel('2026-09-14 10:30:00', $now));
        self::assertSame('还剩 2 小时', $p->expiryStatusLabel('2026-09-14 12:00:00', $now));
        self::assertSame('还剩 7 天', $p->expiryStatusLabel('2026-09-21 10:00:00', $now));
        // %{1} 占位由 __() 替换；文案保持运营可读
        self::assertSame('未设过期', $p->expiryStatusLabel('', $now));
    }
}
