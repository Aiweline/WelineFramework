<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Extends\Module\Weline_Order\BackendLifecycleStagePolicy\TobBackendLifecycleStagePolicy;
use Weline\B2B\Model\B2BOrderHang;

final class TobBackendLifecycleStagePolicyTest extends TestCase
{
    public function testSupportsRequiresHangStatus(): void
    {
        $p = new TobBackendLifecycleStagePolicy();
        self::assertFalse($p->supports(['order_type' => 'tob', 'type_payload' => []]));
        self::assertTrue($p->supports([
            'order_type' => 'tob',
            'type_payload' => ['hang_status' => B2BOrderHang::STATUS_AWAITING_BALANCE],
        ]));
    }

    public function testPresentBalanceStage(): void
    {
        $p = new TobBackendLifecycleStagePolicy();
        $view = $p->present([
            'order_type' => 'tob',
            'type_payload' => ['hang_status' => B2BOrderHang::STATUS_AWAITING_BALANCE],
        ]);
        self::assertNotNull($view);
        self::assertSame(B2BOrderHang::STATUS_AWAITING_BALANCE, $view->currentCode);
        self::assertSame('待付尾款', $view->currentLabel);
        self::assertCount(4, $view->steps);
        self::assertSame('current', $view->steps[2]['state']);
    }

    public function testPresentBalanceOmitsMerchantStepWhenReviewNotRequired(): void
    {
        $p = new TobBackendLifecycleStagePolicy();
        $view = $p->present([
            'order_type' => 'tob',
            'type_payload' => [
                'hang_status' => B2BOrderHang::STATUS_AWAITING_BALANCE,
                'merchant_review_required' => false,
            ],
        ]);
        self::assertNotNull($view);
        self::assertCount(3, $view->steps);
        $codes = array_column($view->steps, 'code');
        self::assertNotContains(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $codes);
        self::assertSame('current', $view->steps[1]['state']);
        self::assertSame([], $view->actions);
    }

    public function testCompletedLabelIsPaymentCompleteOnBackend(): void
    {
        $p = new TobBackendLifecycleStagePolicy();
        $view = $p->present([
            'type_payload' => ['hang_status' => B2BOrderHang::STATUS_COMPLETED],
        ]);
        self::assertSame('支付完成', $view->currentLabel);
    }

    public function testSellerReviewActionsOnlyWhenAwaitingMerchantApproval(): void
    {
        $p = new TobBackendLifecycleStagePolicy();
        // No live hang row: force type_payload path (avoid colliding with real fixtures).
        $row = [
            'order_id' => '991235',
            'order_uuid' => 'ut-seller-review-actions-only',
            'type_payload' => ['hang_status' => B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL],
        ];
        $view = $p->present($row);
        self::assertNotNull($view);
        self::assertCount(2, $view->actions);
        self::assertSame('approve', $view->actions[0]['code']);
        self::assertSame('通过审查', $view->actions[0]['label']);
        self::assertSame('b2b/backend/control-center/approve-hang-order', $view->actions[0]['path']);
        self::assertSame('991235', $view->actions[0]['params']['order_id']);
        self::assertSame('reject', $view->actions[1]['code']);

        $balance = $p->present([
            'order_id' => '991235',
            'order_uuid' => 'ut-seller-review-actions-only',
            'type_payload' => ['hang_status' => B2BOrderHang::STATUS_AWAITING_BALANCE],
        ]);
        self::assertNotNull($balance);
        self::assertSame([], $balance->actions);
    }
}
