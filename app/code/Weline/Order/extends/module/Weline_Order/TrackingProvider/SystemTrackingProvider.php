<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Order\TrackingProvider;

use Throwable;
use Weline\Order\Api\Data\Tracking\TrackingFeedbackRequest;
use Weline\Order\Api\Data\Tracking\TrackingFeedbackResult;
use Weline\Order\Api\Data\Tracking\TrackingProviderError;
use Weline\Order\Api\Data\Tracking\TrackingQueryRequest;
use Weline\Order\Api\Data\Tracking\TrackingResult;
use Weline\Order\Api\Data\Tracking\TrackingTestConnectionRequest;
use Weline\Order\Interface\TrackingProviderInterface;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;

/**
 * 系统内置订单追踪：无正式物流商时展示订单流转（已发货 → 发往目的地）。
 */
final class SystemTrackingProvider implements TrackingProviderInterface
{
    public function getCode(): string
    {
        return 'system';
    }

    public function getProviderCode(): string
    {
        return 'weline_system';
    }

    public function getProviderApiVersion(): string
    {
        return '1.0';
    }

    public function getWebhookSchemaVersion(): string
    {
        return '1.0';
    }

    public function getCapabilities(): array
    {
        return [
            'formal_carrier' => false,
            'feedback_webhook' => false,
            'live_nodes' => false,
            'system_fallback' => true,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => (string) __('系统订单追踪'),
            'description' => (string) __('暂无第三方物流商时，按订单履约状态展示系统追踪。'),
            'icon_url' => 'Weline_Order::img/tracking/system.svg',
            'icon' => 'Weline_Order::img/tracking/system.svg',
            'track_template_code' => 'system',
        ];
    }

    public function getFlowStages(): array
    {
        return [
            ['code' => 'placed', 'label' => (string) __('已下单'), 'sort' => 10, 'icon' => 'receipt'],
            ['code' => 'paid', 'label' => (string) __('已支付'), 'sort' => 20, 'icon' => 'credit-card'],
            ['code' => 'shipped', 'label' => (string) __('已发货'), 'sort' => 30, 'icon' => 'package'],
            ['code' => 'in_transit', 'label' => (string) __('发往目的地'), 'sort' => 40, 'icon' => 'truck'],
            ['code' => 'delivered', 'label' => (string) __('已送达'), 'sort' => 50, 'icon' => 'check-circle'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function queryTracking(TrackingQueryRequest $request): TrackingResult
    {
        $stageCode = $this->resolveCurrentStage($request);
        $stages = $this->markStages($this->getFlowStages(), $stageCode);
        $nodes = $this->buildNodes($request, $stageCode);
        $status = match ($stageCode) {
            'delivered' => TrackingResult::STATUS_DELIVERED,
            'in_transit' => TrackingResult::STATUS_IN_TRANSIT,
            'shipped' => TrackingResult::STATUS_SHIPPED,
            default => TrackingResult::STATUS_PENDING,
        };

        $summary = match ($stageCode) {
            'delivered' => (string) __('订单已送达'),
            'in_transit', 'shipped' => (string) __('已发货，发往目的地'),
            'paid' => (string) __('订单已支付，等待发货'),
            default => (string) __('订单处理中'),
        };

        return TrackingResult::fromArray([
            TrackingResult::FIELD_STATUS => $status,
            TrackingResult::FIELD_PROVIDER_CODE => $this->getProviderCode(),
            TrackingResult::FIELD_METHOD_CODE => $this->getCode(),
            TrackingResult::FIELD_CURRENT_STAGE_CODE => $stageCode,
            TrackingResult::FIELD_SUMMARY => $summary,
            TrackingResult::FIELD_TRACKING_NUMBER => $request->getTrackingNumber(),
            TrackingResult::FIELD_STAGES => $stages,
            TrackingResult::FIELD_NODES => $nodes,
            TrackingResult::FIELD_DISPLAY => $this->getDisplayMetadata(),
            TrackingResult::FIELD_MESSAGE => $summary,
            TrackingResult::FIELD_PAYLOAD => [
                'mode' => 'system',
                'destination_summary' => $request->getDestinationSummary(),
            ],
        ]);
    }

    public function verifyFeedback(TrackingFeedbackRequest $request): TrackingFeedbackResult
    {
        return TrackingFeedbackResult::fromArray([
            TrackingFeedbackResult::FIELD_VALID => false,
            TrackingFeedbackResult::FIELD_MESSAGE => (string) __('系统追踪不接收外部物流反馈'),
        ]);
    }

    public function parseFeedback(TrackingFeedbackRequest $request): TrackingFeedbackResult
    {
        return $this->verifyFeedback($request);
    }

    public function testConnection(TrackingTestConnectionRequest $request): TrackingResult
    {
        return TrackingResult::fromArray([
            TrackingResult::FIELD_STATUS => TrackingResult::STATUS_PENDING,
            TrackingResult::FIELD_PROVIDER_CODE => $this->getProviderCode(),
            TrackingResult::FIELD_METHOD_CODE => $this->getCode(),
            TrackingResult::FIELD_SUMMARY => (string) __('系统追踪无需外部连接'),
            TrackingResult::FIELD_MESSAGE => (string) __('系统追踪无需外部连接'),
            TrackingResult::FIELD_DISPLAY => $this->getDisplayMetadata(),
        ]);
    }

    public function normalizeError(Throwable|array $error): TrackingProviderError
    {
        if (is_array($error)) {
            return TrackingProviderError::fromArray($error);
        }

        return TrackingProviderError::fromArray([
            TrackingProviderError::FIELD_CODE => 'system_tracking_error',
            TrackingProviderError::FIELD_MESSAGE => $error->getMessage(),
            TrackingProviderError::FIELD_RETRYABLE => false,
            TrackingProviderError::FIELD_USER_VISIBLE => true,
        ]);
    }

    private function resolveCurrentStage(TrackingQueryRequest $request): string
    {
        $fulfillment = strtolower($request->getFulfillmentStatus());
        $orderStatus = strtolower($request->getOrderStatus());
        $shipmentStatus = strtolower((string) ($request->getContext()['shipment_status'] ?? ''));

        if (in_array($fulfillment, [Order::FULFILLMENT_STATUS_DELIVERED, 'delivered'], true)
            || in_array($orderStatus, [Order::STATUS_COMPLETED, 'complete', 'completed'], true)
            || $shipmentStatus === OrderShipment::STATUS_DELIVERED) {
            return 'delivered';
        }

        if (in_array($fulfillment, [Order::FULFILLMENT_STATUS_SHIPPED, 'shipped', 'partially_shipped'], true)
            || in_array($orderStatus, [Order::STATUS_FULFILLED, 'fulfilled', 'shipped'], true)
            || in_array($shipmentStatus, [OrderShipment::STATUS_SHIPPED, OrderShipment::STATUS_IN_TRANSIT], true)
            || $request->getShippedAt() !== null) {
            // 无正式承运商：发货后统一展示「已发货 / 发往目的地」当前阶段
            return 'in_transit';
        }

        if (in_array($orderStatus, [Order::STATUS_PAID, 'paid'], true)) {
            return 'paid';
        }

        return 'placed';
    }

    /**
     * @param list<array<string, mixed>> $stages
     * @return list<array<string, mixed>>
     */
    private function markStages(array $stages, string $currentCode): array
    {
        $currentSort = 0;
        foreach ($stages as $stage) {
            if (($stage['code'] ?? '') === $currentCode) {
                $currentSort = (int) ($stage['sort'] ?? 0);
                break;
            }
        }

        $out = [];
        foreach ($stages as $stage) {
            $sort = (int) ($stage['sort'] ?? 0);
            $code = (string) ($stage['code'] ?? '');
            $state = 'upcoming';
            if ($code === $currentCode) {
                $state = 'current';
            } elseif ($sort < $currentSort) {
                $state = 'done';
            }
            $stage['state'] = $state;
            $out[] = $stage;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildNodes(TrackingQueryRequest $request, string $stageCode): array
    {
        $nodes = [
            [
                'code' => 'placed',
                'time' => null,
                'text' => (string) __('订单已创建'),
            ],
        ];

        if (in_array($stageCode, ['paid', 'shipped', 'in_transit', 'delivered'], true)) {
            $nodes[] = [
                'code' => 'paid',
                'time' => null,
                'text' => (string) __('支付完成，仓库备货中'),
            ];
        }

        if (in_array($stageCode, ['shipped', 'in_transit', 'delivered'], true)) {
            $nodes[] = [
                'code' => 'shipped',
                'time' => $request->getShippedAt(),
                'text' => (string) __('已发货'),
            ];
            $destination = $request->getDestinationSummary() !== ''
                ? $request->getDestinationSummary()
                : (string) __('目的地');
            $nodes[] = [
                'code' => 'in_transit',
                'time' => $request->getShippedAt(),
                'text' => (string) __('发往目的地：%{1}', [$destination]),
            ];
        }

        if ($stageCode === 'delivered') {
            $nodes[] = [
                'code' => 'delivered',
                'time' => null,
                'text' => (string) __('已送达'),
            ];
        }

        return array_reverse($nodes);
    }
}
