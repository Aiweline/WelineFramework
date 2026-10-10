<?php

declare(strict_types=1);

namespace Weline\Order\Api;

use Weline\Order\Api\Data\LifecycleStageView;

/**
 * 后台订单类型生命周期阶段 SPI（与 order_type 徽章分离；Order 壳编排；业务模块按 order_type 实现）。
 */
interface OrderBackendLifecycleStagePolicyInterface
{
    public function getOrderTypeCode(): string;

    /**
     * @param array<string, mixed> $orderRow
     */
    public function supports(array $orderRow): bool;

    /**
     * @param array<string, mixed> $orderRow
     */
    public function present(array $orderRow): ?LifecycleStageView;
}
