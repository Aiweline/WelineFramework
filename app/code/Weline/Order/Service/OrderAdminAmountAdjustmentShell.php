<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\AdjustmentLineDraft;
use Weline\Order\Api\Data\OrderAdjustmentContext;
use Weline\Order\Api\OrderAdminAmountAdjustmentPolicyInterface;
use Weline\Order\Api\OrderFacadeConflictException;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderHistory;
use Weline\Payment\Api\PaymentRefundFacadeInterface;

/**
 * 后台金额调整壳：策略门禁 + Facade 写盘 + 已付地板 + 支付未完成意图作废。
 */
final class OrderAdminAmountAdjustmentShell
{
    public const ERROR_UNSUPPORTED = 'order_admin_amount_unsupported';
    public const ERROR_BELOW_PAID = 'order_admin_amount_below_paid';
    public const ERROR_DENIED = 'order_admin_amount_denied';

    public function __construct(
        private readonly OrderFacadeInterface $orders,
        private readonly OrderAdminAmountAdjustmentPolicyRegistry $policies,
        private readonly ?PaymentRefundFacadeInterface $payments = null,
    ) {
    }

    public function canAdjustOrder(string $orderUuid): bool
    {
        try {
            $ctx = $this->buildContext($orderUuid, 0);
            $policy = $this->policies->get($ctx->orderType);
            if ($policy === null || !$policy->supports()) {
                return false;
            }

            return $policy->canAdjust($ctx)->allowed;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *   money: array<string,mixed>,
     *   type_payload: array<string,mixed>,
     *   line: array<string,mixed>,
     *   grand_total_minor: int,
     *   discount_amount_minor: int
     * }
     */
    public function addDiscountLine(
        string $orderUuid,
        string $label,
        int $discountAmountMinor,
        string $note = '',
        int $adminUserId = 0,
    ): array {
        $ctx = $this->buildContext($orderUuid, $adminUserId);
        $policy = $this->policies->requireSupporting($ctx->orderType);
        $can = $policy->canAdjust($ctx);
        if (!$can->allowed) {
            throw new OrderConflictException(
                $can->reasonCode !== '' ? $can->reasonCode : self::ERROR_DENIED,
                $can->message !== '' ? $can->message : (string)__('不允许调整该订单金额'),
                $can->context,
            );
        }

        $draft = new AdjustmentLineDraft(
            label: trim($label),
            discountAmountMinor: max(0, $discountAmountMinor),
            note: trim($note),
        );
        $lineDecision = $policy->validateLine($ctx, $draft);
        if (!$lineDecision->allowed) {
            throw new OrderConflictException(
                $lineDecision->reasonCode !== '' ? $lineDecision->reasonCode : self::ERROR_DENIED,
                $lineDecision->message !== '' ? $lineDecision->message : (string)__('金额调整行无效'),
                $lineDecision->context,
            );
        }

        $previewGrand = $this->previewGrandAfterAdminDiscount($ctx, $draft->discountAmountMinor);
        if ($previewGrand < $ctx->paidCapturedMinor) {
            throw new OrderConflictException(
                self::ERROR_BELOW_PAID,
                (string)__('优惠后订单总额不能低于已支付金额'),
                [
                    'grand_total_minor' => $previewGrand,
                    'paid_captured_minor' => $ctx->paidCapturedMinor,
                ],
            );
        }

        try {
            $applied = $this->orders->appendAdminAdjustmentLine($orderUuid, [
                'label' => $draft->label,
                'discount_amount_minor' => $draft->discountAmountMinor,
                'note' => $draft->note,
                'admin_user_id' => $adminUserId,
            ]);
        } catch (OrderFacadeConflictException $e) {
            throw new OrderConflictException($e->errorCode(), $e->getMessage(), $e->context(), $e);
        }

        $appliedCtx = $this->buildContext($orderUuid, $adminUserId);
        try {
            $policy->onApplied($appliedCtx, $applied);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('Order admin amount onApplied failed: ' . $e->getMessage());
            }
        }

        $grand = (int)($applied['grand_total_minor'] ?? 0);
        if ($grand === $ctx->paidCapturedMinor && $ctx->paidCapturedMinor > 0) {
            $this->markPaidIfSettled($orderUuid, $ctx->orderId);
            $this->abandonOpenPayments($orderUuid);
        }

        $this->appendHistory(
            $ctx->orderId,
            $ctx->status,
            (string)__(
                '后台金额调整：%{1} −%{2}',
                [$draft->label, number_format($draft->discountAmountMinor / 100, 2, '.', '')],
            ),
        );

        return $applied;
    }

    private function buildContext(string $orderUuid, int $adminUserId): OrderAdjustmentContext
    {
        $order = $this->orders->get($orderUuid);
        $paid = 0;
        $payments = $this->payments();
        if ($payments !== null) {
            try {
                $paid = max(0, $payments->sumCapturedAmountMinor('order', $orderUuid));
                if ($paid === 0) {
                    $paid = max(0, $payments->sumCapturedAmountMinor('weline_order', $orderUuid));
                }
            } catch (\Throwable) {
                $paid = 0;
            }
        }

        return new OrderAdjustmentContext(
            orderUuid: $order->orderUuid,
            orderId: $this->resolveOrderId($order->orderUuid),
            orderType: strtolower(trim($order->orderType !== '' ? $order->orderType : 'toc')) ?: 'toc',
            status: strtolower(trim((string)($order->status ?? ''))),
            paymentStatus: strtolower(trim((string)($order->paymentStatus ?? ''))),
            money: \is_array($order->money) ? $order->money : [],
            typePayload: \is_array($order->typePayload) ? $order->typePayload : [],
            paidCapturedMinor: $paid,
            adminUserId: $adminUserId,
        );
    }

    private function resolveOrderId(string $orderUuid): int
    {
        try {
            /** @var Order $row */
            $row = ObjectManager::getInstance(Order::class);
            $row->clear()
                ->where(Order::schema_fields_ORDER_UUID, trim($orderUuid))
                ->find()
                ->fetch();

            return $row->getId() ? (int)$row->getId() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function previewGrandAfterAdminDiscount(OrderAdjustmentContext $ctx, int $addAdminDiscountMinor): int
    {
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
        $currentDiscount = $ctx->discountMinor();
        $baseNonAdmin = max(0, $currentDiscount - $prevAdmin - $incentive);
        $newDiscount = $baseNonAdmin + $prevAdmin + max(0, $addAdminDiscountMinor) + $incentive;

        return max(
            0,
            $ctx->subtotalMinor() + $ctx->shippingMinor() + $ctx->taxMinor() + $ctx->codFeeMinor() - $newDiscount,
        );
    }

    private function markPaidIfSettled(string $orderUuid, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            /** @var Order $row */
            $row = ObjectManager::getInstance(Order::class);
            $row->clear()->load($orderId);
            if (!$row->getId()) {
                return;
            }
            $paymentStatus = strtolower(trim((string)$row->getData(Order::schema_fields_PAYMENT_STATUS)));
            if (\in_array($paymentStatus, [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_REFUNDED], true)) {
                return;
            }
            $row->setData(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID)->save();
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('Order admin amount mark paid failed ' . $orderUuid . ': ' . $e->getMessage());
            }
        }
    }

    private function abandonOpenPayments(string $orderUuid): void
    {
        $payments = $this->payments();
        if ($payments === null) {
            return;
        }
        try {
            $payments->abandonOpenIntentsForPayable('order', $orderUuid, 'admin_amount_adjusted');
            $payments->abandonOpenIntentsForPayable('weline_order', $orderUuid, 'admin_amount_adjusted');
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('Order admin amount abandon intents failed: ' . $e->getMessage());
            }
        }
    }

    private function appendHistory(int $orderId, string $status, string $comment): void
    {
        if ($orderId <= 0 || $comment === '') {
            return;
        }
        try {
            /** @var OrderHistory $history */
            $history = ObjectManager::getInstance(OrderHistory::class);
            $history->setData(OrderHistory::schema_fields_ORDER_ID, $orderId)
                ->setData(OrderHistory::schema_fields_STATUS, $status !== '' ? $status : Order::STATUS_PENDING)
                ->setData(OrderHistory::schema_fields_COMMENT, $comment)
                ->setData(OrderHistory::schema_fields_IS_CUSTOMER_NOTIFIED, 0)
                ->save();
        } catch (\Throwable) {
        }
    }

    private function payments(): ?PaymentRefundFacadeInterface
    {
        if ($this->payments instanceof PaymentRefundFacadeInterface) {
            return $this->payments;
        }
        if (!interface_exists(PaymentRefundFacadeInterface::class)) {
            return null;
        }
        try {
            $instance = ObjectManager::getInstance(PaymentRefundFacadeInterface::class);
            return $instance instanceof PaymentRefundFacadeInterface ? $instance : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
