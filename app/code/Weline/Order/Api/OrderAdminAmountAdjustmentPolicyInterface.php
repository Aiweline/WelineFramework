<?php

declare(strict_types=1);

namespace Weline\Order\Api;

use Weline\Order\Api\Data\AdjustmentDecision;
use Weline\Order\Api\Data\AdjustmentLineDraft;
use Weline\Order\Api\Data\OrderAdjustmentContext;

/**
 * 后台订单金额调整类型策略 SPI（Order 壳编排；业务模块按 order_type 实现）。
 */
interface OrderAdminAmountAdjustmentPolicyInterface
{
    public const SOURCE_TYPE = 'admin_adjustment';

    public function getOrderTypeCode(): string;

    public function supports(): bool;

    public function canAdjust(OrderAdjustmentContext $ctx): AdjustmentDecision;

    public function validateLine(OrderAdjustmentContext $ctx, AdjustmentLineDraft $draft): AdjustmentDecision;

    /**
     * 壳写盘成功后的类型侧副作用（如 tob 同步定金/尾款快照）。不得绕过挂单买家确认流。
     *
     * @param array{
     *   line: array<string,mixed>,
     *   discount_amount_minor: int,
     *   grand_total_minor: int,
     *   type_payload: array<string,mixed>
     * } $applied
     */
    public function onApplied(OrderAdjustmentContext $ctx, array $applied): void;
}
