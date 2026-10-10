<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Order\AdminAmountAdjustmentPolicy;

use Weline\B2B\Extends\Module\Weline_Order\CommerceOrderType\TobCommerceOrderType;
use Weline\Order\Api\Data\AdjustmentDecision;
use Weline\Order\Api\Data\AdjustmentLineDraft;
use Weline\Order\Api\Data\OrderAdjustmentContext;
use Weline\Order\Api\OrderAdminAmountAdjustmentPolicyInterface;
use Weline\Order\Service\DiscountLinesBySourceMerger;

/**
 * 批发订单（tob）后台金额调整策略：允许加优惠小计项；挂单待确认改价时拒绝。
 */
final class TobAdminAmountAdjustmentPolicy implements OrderAdminAmountAdjustmentPolicyInterface
{
    public function getOrderTypeCode(): string
    {
        return TobCommerceOrderType::CODE;
    }

    public function supports(): bool
    {
        return true;
    }

    public function canAdjust(OrderAdjustmentContext $ctx): AdjustmentDecision
    {
        $status = strtolower(trim($ctx->status));
        if (\in_array($status, ['cancelled', 'completed', 'refunded', 'closed'], true)) {
            return AdjustmentDecision::deny(
                'tob_admin_amount_terminal_status',
                (string)__('终态订单不可调整金额'),
                ['status' => $status],
            );
        }
        if ((bool)($ctx->typePayload['hang_revision_pending'] ?? false)) {
            return AdjustmentDecision::deny(
                'tob_admin_amount_hang_revision_pending',
                (string)__('存在待确认的挂单尾款改价，请先完成或取消后再调整'),
            );
        }
        $hangStatus = strtolower(trim((string)($ctx->typePayload['hang_status'] ?? '')));
        if ($hangStatus === '' && isset($ctx->typePayload['b2b_hang_status'])) {
            $hangStatus = strtolower(trim((string)$ctx->typePayload['b2b_hang_status']));
        }
        if ($hangStatus === 'awaiting_balance') {
            return AdjustmentDecision::deny(
                'tob_admin_amount_use_hang_revision',
                (string)__('挂单待付尾款请使用挂单改价流程，勿在此直接调整'),
                ['hang_status' => $hangStatus],
            );
        }

        return AdjustmentDecision::allow();
    }

    public function validateLine(OrderAdjustmentContext $ctx, AdjustmentLineDraft $draft): AdjustmentDecision
    {
        if (trim($draft->label) === '') {
            return AdjustmentDecision::deny(
                'tob_admin_amount_label_required',
                (string)__('请填写优惠说明'),
            );
        }
        if ($draft->discountAmountMinor <= 0) {
            return AdjustmentDecision::deny(
                'tob_admin_amount_discount_required',
                (string)__('优惠金额必须大于 0'),
            );
        }

        $lines = \is_array($ctx->typePayload['discount_lines'] ?? null)
            ? $ctx->typePayload['discount_lines']
            : [];
        $prevAdmin = max(
            0,
            (int)($ctx->typePayload['admin_adjustment_amount_minor'] ?? 0),
            DiscountLinesBySourceMerger::absSumForSource($lines, DiscountLinesBySourceMerger::adminSource()),
        );
        $incentive = max(
            0,
            abs((int)($ctx->typePayload['payment_method_incentive_amount_minor'] ?? 0)),
            DiscountLinesBySourceMerger::absSumForSource(
                $lines,
                DiscountLinesBySourceMerger::SOURCE_PAYMENT_INCENTIVE,
            ),
        );
        $baseNonAdmin = max(0, $ctx->discountMinor() - $prevAdmin - $incentive);
        $newDiscount = $baseNonAdmin + $prevAdmin + $draft->discountAmountMinor + $incentive;
        $newGrand = max(
            0,
            $ctx->subtotalMinor() + $ctx->shippingMinor() + $ctx->taxMinor() + $ctx->codFeeMinor() - $newDiscount,
        );
        if ($newGrand < $ctx->paidCapturedMinor) {
            $maxDiscount = max(
                0,
                $ctx->subtotalMinor() + $ctx->shippingMinor() + $ctx->taxMinor() + $ctx->codFeeMinor()
                - $baseNonAdmin - $prevAdmin - $incentive - $ctx->paidCapturedMinor,
            );

            return AdjustmentDecision::deny(
                'tob_admin_amount_below_paid',
                (string)__('优惠后总额不能低于已支付金额'),
                [
                    'max_discount_amount_minor' => $maxDiscount,
                    'paid_captured_minor' => $ctx->paidCapturedMinor,
                    'preview_grand_total_minor' => $newGrand,
                ],
            );
        }

        return AdjustmentDecision::allow([
            'preview_grand_total_minor' => $newGrand,
        ]);
    }

    public function onApplied(OrderAdjustmentContext $ctx, array $applied): void
    {
        // 非挂单待尾款：同步 type_payload 应付快照 + hang 表尾款（结账/支付读 hang）。
        $grand = max(0, (int)($applied['grand_total_minor'] ?? 0));
        $deposit = max(0, (int)($ctx->typePayload['deposit_amount_minor'] ?? 0));
        if ($deposit <= 0 && !isset($ctx->typePayload['payable_grand_total_minor'])) {
            return;
        }
        $balance = max(0, $grand - $deposit);
        try {
            $facade = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Order\Api\OrderFacadeInterface::class,
            );
            if ($facade instanceof \Weline\Order\Api\OrderFacadeInterface) {
                $facade->mergeTypePayload($ctx->orderUuid, [
                    'payable_grand_total_minor' => $grand,
                    'balance_amount_minor' => $balance,
                    'admin_adjustment_synced_at' => gmdate('c'),
                ]);
            }
        } catch (\Throwable) {
        }
        try {
            $hang = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\B2B\Service\B2BHangOrderService::class,
            );
            if ($hang instanceof \Weline\B2B\Service\B2BHangOrderService) {
                $hang->syncBalanceAmountMinor($ctx->orderUuid, $balance);
            }
        } catch (\Throwable) {
        }
    }
}
