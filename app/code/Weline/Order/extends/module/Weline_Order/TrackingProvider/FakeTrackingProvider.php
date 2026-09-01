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

/**
 * 开发/演示用正式物流 Provider（对齐 Payment FakeProvider）。
 */
final class FakeTrackingProvider implements TrackingProviderInterface
{
    public function getCode(): string
    {
        return 'fake_carrier';
    }

    public function getProviderCode(): string
    {
        return 'fake';
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
            'formal_carrier' => true,
            'feedback_webhook' => true,
            'live_nodes' => true,
            'accept_unregistered_carrier' => false,
            'carrier_aliases' => ['fake', 'fake_carrier', 'demo'],
            'dev_only' => true,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => (string) __('演示物流'),
            'description' => (string) __('仅用于本地开发验证正式物流轨迹与反馈中转，不连接真实承运商。'),
            'icon_url' => 'Weline_Order::img/tracking/fake-carrier.svg',
            'icon' => 'Weline_Order::img/tracking/fake-carrier.svg',
            'track_template_code' => 'fake_carrier',
            'config_template_code' => 'fake_carrier',
        ];
    }

    public function getFlowStages(): array
    {
        return [
            ['code' => 'picked_up', 'label' => (string) __('已揽收'), 'sort' => 10, 'icon' => 'package'],
            ['code' => 'in_transit', 'label' => (string) __('运输中'), 'sort' => 20, 'icon' => 'truck'],
            ['code' => 'out_for_delivery', 'label' => (string) __('派送中'), 'sort' => 30, 'icon' => 'map-pin'],
            ['code' => 'delivered', 'label' => (string) __('已签收'), 'sort' => 40, 'icon' => 'check-circle'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'environment' => [
                'type' => 'select',
                'required' => true,
                'label' => 'Environment',
                'default' => 'sandbox',
                'options' => ['sandbox' => 'Sandbox'],
            ],
        ];
    }

    public function queryTracking(TrackingQueryRequest $request): TrackingResult
    {
        $trackingNumber = $request->getTrackingNumber() !== ''
            ? $request->getTrackingNumber()
            : ('FAKE-' . $request->getOrderNumber());

        $stages = [];
        foreach ($this->getFlowStages() as $stage) {
            $code = (string) ($stage['code'] ?? '');
            $stage['state'] = match ($code) {
                'picked_up', 'in_transit' => 'done',
                'out_for_delivery' => 'current',
                default => 'upcoming',
            };
            $stages[] = $stage;
        }

        $nodes = [
            [
                'code' => 'out_for_delivery',
                'time' => date('c'),
                'text' => (string) __('【演示】快递员正在派送'),
            ],
            [
                'code' => 'in_transit',
                'time' => date('c', time() - 3600),
                'text' => (string) __('【演示】包裹已到达目的地转运中心'),
            ],
            [
                'code' => 'picked_up',
                'time' => $request->getShippedAt() ?: date('c', time() - 86400),
                'text' => (string) __('【演示】承运商已揽收'),
            ],
        ];

        return TrackingResult::fromArray([
            TrackingResult::FIELD_STATUS => TrackingResult::STATUS_OUT_FOR_DELIVERY,
            TrackingResult::FIELD_PROVIDER_CODE => $this->getProviderCode(),
            TrackingResult::FIELD_METHOD_CODE => $this->getCode(),
            TrackingResult::FIELD_CURRENT_STAGE_CODE => 'out_for_delivery',
            TrackingResult::FIELD_SUMMARY => (string) __('【演示】包裹派送中'),
            TrackingResult::FIELD_TRACKING_NUMBER => $trackingNumber,
            TrackingResult::FIELD_EXTERNAL_URL => null,
            TrackingResult::FIELD_STAGES => $stages,
            TrackingResult::FIELD_NODES => $nodes,
            TrackingResult::FIELD_DISPLAY => $this->getDisplayMetadata(),
            TrackingResult::FIELD_MESSAGE => (string) __('【演示】包裹派送中'),
            TrackingResult::FIELD_PAYLOAD => [
                'mode' => 'fake',
                'carrier' => $request->getCarrier() ?: 'fake_carrier',
            ],
        ]);
    }

    public function verifyFeedback(TrackingFeedbackRequest $request): TrackingFeedbackResult
    {
        $body = $request->getRawBody();
        if ($body === '') {
            return TrackingFeedbackResult::fromArray([
                TrackingFeedbackResult::FIELD_VALID => false,
                TrackingFeedbackResult::FIELD_MESSAGE => (string) __('空反馈体'),
            ]);
        }

        $headers = array_change_key_case($request->getHeaders(), CASE_LOWER);
        $token = (string) ($headers['x-weline-fake-tracking'] ?? '');
        if ($token !== 'dev') {
            return TrackingFeedbackResult::fromArray([
                TrackingFeedbackResult::FIELD_VALID => false,
                TrackingFeedbackResult::FIELD_MESSAGE => (string) __('演示反馈签名无效'),
            ]);
        }

        return TrackingFeedbackResult::fromArray([
            TrackingFeedbackResult::FIELD_VALID => true,
            TrackingFeedbackResult::FIELD_MESSAGE => (string) __('演示反馈验签通过'),
        ]);
    }

    public function parseFeedback(TrackingFeedbackRequest $request): TrackingFeedbackResult
    {
        $decoded = json_decode($request->getRawBody(), true);
        if (!is_array($decoded)) {
            return TrackingFeedbackResult::fromArray([
                TrackingFeedbackResult::FIELD_VALID => false,
                TrackingFeedbackResult::FIELD_MESSAGE => (string) __('演示反馈 JSON 无效'),
            ]);
        }

        return TrackingFeedbackResult::fromArray([
            TrackingFeedbackResult::FIELD_VALID => true,
            TrackingFeedbackResult::FIELD_EVENT_ID => (string) ($decoded['event_id'] ?? ('fake-' . sha1($request->getRawBody()))),
            TrackingFeedbackResult::FIELD_EVENT_TYPE => (string) ($decoded['event_type'] ?? 'tracking.updated'),
            TrackingFeedbackResult::FIELD_ORDER_NUMBER => (string) ($decoded['order_number'] ?? ''),
            TrackingFeedbackResult::FIELD_TRACKING_NUMBER => (string) ($decoded['tracking_number'] ?? ''),
            TrackingFeedbackResult::FIELD_SUGGESTED_STATUS => (string) ($decoded['status'] ?? TrackingResult::STATUS_IN_TRANSIT),
            TrackingFeedbackResult::FIELD_STAGE_CODE => (string) ($decoded['stage_code'] ?? 'in_transit'),
            TrackingFeedbackResult::FIELD_SUMMARY => (string) ($decoded['summary'] ?? __('【演示】物流状态已更新')),
            TrackingFeedbackResult::FIELD_NODES => is_array($decoded['nodes'] ?? null) ? $decoded['nodes'] : [],
            TrackingFeedbackResult::FIELD_PAYLOAD => $decoded,
        ]);
    }

    public function testConnection(TrackingTestConnectionRequest $request): TrackingResult
    {
        return TrackingResult::fromArray([
            TrackingResult::FIELD_STATUS => TrackingResult::STATUS_PENDING,
            TrackingResult::FIELD_PROVIDER_CODE => $this->getProviderCode(),
            TrackingResult::FIELD_METHOD_CODE => $this->getCode(),
            TrackingResult::FIELD_SUMMARY => (string) __('演示物流连接正常'),
            TrackingResult::FIELD_MESSAGE => (string) __('演示物流连接正常'),
            TrackingResult::FIELD_DISPLAY => $this->getDisplayMetadata(),
        ]);
    }

    public function normalizeError(Throwable|array $error): TrackingProviderError
    {
        if (is_array($error)) {
            return TrackingProviderError::fromArray($error);
        }

        return TrackingProviderError::fromArray([
            TrackingProviderError::FIELD_CODE => 'fake_tracking_error',
            TrackingProviderError::FIELD_MESSAGE => $error->getMessage(),
            TrackingProviderError::FIELD_RETRYABLE => true,
            TrackingProviderError::FIELD_USER_VISIBLE => true,
        ]);
    }
}
