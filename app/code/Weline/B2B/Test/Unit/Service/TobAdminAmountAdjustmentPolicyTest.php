<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Extends\Module\Weline_Order\AdminAmountAdjustmentPolicy\TobAdminAmountAdjustmentPolicy;
use Weline\Order\Api\Data\AdjustmentLineDraft;
use Weline\Order\Api\Data\OrderAdjustmentContext;

final class TobAdminAmountAdjustmentPolicyTest extends TestCase
{
    public function testSupportsTobAndRejectsTerminalAndHangPending(): void
    {
        $policy = new TobAdminAmountAdjustmentPolicy();
        self::assertTrue($policy->supports());
        self::assertSame('tob', $policy->getOrderTypeCode());

        $ok = $policy->canAdjust($this->ctx());
        self::assertTrue($ok->allowed);

        $terminal = $policy->canAdjust($this->ctx(status: 'cancelled'));
        self::assertFalse($terminal->allowed);

        $hangPending = $policy->canAdjust($this->ctx(typePayload: ['hang_revision_pending' => true]));
        self::assertFalse($hangPending->allowed);
        self::assertSame('tob_admin_amount_hang_revision_pending', $hangPending->reasonCode);

        $awaiting = $policy->canAdjust($this->ctx(typePayload: ['hang_status' => 'awaiting_balance']));
        self::assertFalse($awaiting->allowed);
        self::assertSame('tob_admin_amount_use_hang_revision', $awaiting->reasonCode);
    }

    public function testValidateLineRequiresLabelAndRespectsPaidFloor(): void
    {
        $policy = new TobAdminAmountAdjustmentPolicy();
        $emptyLabel = $policy->validateLine($this->ctx(), new AdjustmentLineDraft('', 100));
        self::assertFalse($emptyLabel->allowed);

        $belowPaid = $policy->validateLine(
            $this->ctx(paidCapturedMinor: 9000),
            new AdjustmentLineDraft('议价', 2000),
        );
        self::assertFalse($belowPaid->allowed);
        self::assertSame('tob_admin_amount_below_paid', $belowPaid->reasonCode);

        $ok = $policy->validateLine($this->ctx(paidCapturedMinor: 0), new AdjustmentLineDraft('议价', 1000));
        self::assertTrue($ok->allowed);
    }

    /**
     * @param array<string, mixed> $typePayload
     * @param array<string, mixed> $money
     */
    private function ctx(
        string $status = 'pending',
        array $typePayload = [],
        int $paidCapturedMinor = 0,
        array $money = [],
    ): OrderAdjustmentContext {
        $defaultMoney = [
            'currency' => 'CNY',
            'subtotal_minor' => 10000,
            'shipping_amount_minor' => 0,
            'tax_amount_minor' => 0,
            'discount_amount_minor' => 0,
            'grand_total_minor' => 10000,
            'cod_fee_amount_minor' => 0,
        ];

        return new OrderAdjustmentContext(
            orderUuid: 'ord-tob-1',
            orderId: 1,
            orderType: 'tob',
            status: $status,
            paymentStatus: 'pending',
            money: $money !== [] ? $money : $defaultMoney,
            typePayload: $typePayload,
            paidCapturedMinor: $paidCapturedMinor,
            adminUserId: 1,
        );
    }
}
