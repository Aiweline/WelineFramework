<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 管理订单「办理」异步面板：按单号过滤仓维发货/退款候选。
 */
final class BackendOrderOpsPanelPresenter
{
    /** @param OrderTradeAdminCommandService $commands */
    public function __construct(
        private readonly object $commands,
    ) {
    }

    /**
     * @return array{
     *   candidates: list<array<string,mixed>>,
     *   progress: list<array<string,mixed>>,
     *   shipping_ref: array<string,mixed>,
     *   tracking_carriers: list<array<string,mixed>>,
     *   label_services: list<array<string,mixed>>,
     *   shipments: list<array<string,mixed>>
     * }
     */
    public function shipmentPanel(int $orderId): array
    {
        $orderId = max(0, $orderId);
        $meta = method_exists($this->commands, 'shipmentPanelMeta')
            ? $this->commands->shipmentPanelMeta($orderId)
            : ['shipping_ref' => [], 'tracking_carriers' => [], 'label_services' => []];

        $shipments = [];
        try {
            $rows = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Order\Model\OrderShipment::class,
            )->reset()
                ->where(\Weline\Order\Model\OrderShipment::schema_fields_ORDER_ID, $orderId)
                ->order(\Weline\Order\Model\OrderShipment::schema_fields_ID, 'DESC')
                ->limit(20)
                ->select()
                ->fetchArray();
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (\is_array($row)) {
                    $shipments[] = $row;
                }
            }
        } catch (\Throwable) {
            $shipments = [];
        }

        return [
            'candidates' => $this->filterByOrderId($this->commands->shipmentCandidates(80), $orderId),
            'progress' => $this->filterByOrderId($this->commands->shipmentProgress(80), $orderId),
            'shipping_ref' => \is_array($meta['shipping_ref'] ?? null) ? $meta['shipping_ref'] : [],
            'tracking_carriers' => \is_array($meta['tracking_carriers'] ?? null) ? $meta['tracking_carriers'] : [],
            'label_services' => \is_array($meta['label_services'] ?? null) ? $meta['label_services'] : [],
            'shipments' => $shipments,
        ];
    }

    /**
     * @return array{
     *   candidates: list<array<string,mixed>>,
     *   cases: list<array<string,mixed>>
     * }
     */
    public function refundPanel(int $orderId): array
    {
        $orderId = max(0, $orderId);

        return [
            'candidates' => $this->filterByOrderId(
                $this->commands->refundCandidates(80, $orderId),
                $orderId,
            ),
            'cases' => $this->filterByOrderId(
                $this->commands->refundCases(80, $orderId),
                $orderId,
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function filterByOrderId(array $rows, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if ((int)($row['order_id'] ?? 0) === $orderId) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
