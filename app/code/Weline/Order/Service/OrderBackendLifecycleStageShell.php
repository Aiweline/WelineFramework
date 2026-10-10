<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\LifecycleStageView;
use Weline\Order\Model\Order;

/**
 * 后台生命周期阶段壳：按 order_type 解析策略；无策略 / 不支持则空。
 */
final class OrderBackendLifecycleStageShell
{
    public function __construct(
        private readonly OrderBackendLifecycleStagePolicyRegistry $policies,
    ) {
    }

    /**
     * @param Order|array<string, mixed> $order
     * @return array{
     *   title: string,
     *   current_code: string,
     *   current_label: string,
     *   tone: string,
     *   steps: list<array{code: string, label: string, state: string, tone: string}>,
     *   actions: list<array{code: string, label: string, tone: string, path: string, params: array<string, string>, testid?: string}>
     * }|null
     */
    public function presentForOrder(Order|array $order): ?array
    {
        $row = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($row)) {
            return null;
        }
        $orderType = strtolower(trim((string)($row[Order::schema_fields_ORDER_TYPE] ?? $row['order_type'] ?? '')));
        if ($orderType === '') {
            $orderType = CommerceOrderTypeRegistry::CODE_TOC;
        }
        $policy = $this->policies->get($orderType);
        if ($policy === null || !$policy->supports($row)) {
            return null;
        }
        $view = $policy->present($row);
        if (!$view instanceof LifecycleStageView) {
            return null;
        }

        return $view->toArray();
    }
}
