<?php

declare(strict_types=1);

namespace Weline\Order\Service\Tracking;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\Tracking\TrackingFeedbackRequest;
use Weline\Order\Interface\TrackingProviderInterface;
use Weline\Order\Model\OrderTrackingFeedbackInbox;

/**
 * 物流反馈接收壳：验签/解析由 Provider 纯函数完成，持久化与事件只在壳内发生。
 */
final class OrderTrackingFeedbackReceiver
{
    public function __construct(
        private readonly OrderTrackingProviderManager $providerManager,
        private readonly ObjectManager $objectManager,
        private readonly EventsManager $eventsManager,
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $query
     * @return array{ok:bool,http_status:int,inbox_code?:string,message:string,duplicate?:bool}
     */
    public function receive(
        string $endpointCode,
        string $rawBody,
        array $headers = [],
        array $query = [],
    ): array {
        $endpointCode = trim($endpointCode);
        [$methodCode, $environment] = $this->parseEndpointCode($endpointCode);
        if ($methodCode === '') {
            return [
                'ok' => false,
                'http_status' => 400,
                'message' => (string) __('endpoint_code 无效'),
            ];
        }

        $provider = $this->providerManager->getProvider($methodCode);
        if (!$provider instanceof TrackingProviderInterface) {
            return [
                'ok' => false,
                'http_status' => 404,
                'message' => (string) __('跟踪 Provider 不存在'),
            ];
        }

        $request = TrackingFeedbackRequest::fromArray([
            TrackingFeedbackRequest::FIELD_ENDPOINT_CODE => $endpointCode,
            TrackingFeedbackRequest::FIELD_METHOD_CODE => $methodCode,
            TrackingFeedbackRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            TrackingFeedbackRequest::FIELD_RAW_BODY => $rawBody,
            TrackingFeedbackRequest::FIELD_HEADERS => $headers,
            TrackingFeedbackRequest::FIELD_QUERY => $query,
            TrackingFeedbackRequest::FIELD_CONTEXT => [
                'environment' => $environment,
            ],
        ]);

        $verified = $provider->verifyFeedback($request);
        if (!$verified->isValid()) {
            return [
                'ok' => false,
                'http_status' => 401,
                'message' => $verified->getMessage() !== ''
                    ? $verified->getMessage()
                    : (string) __('物流反馈验签失败'),
            ];
        }

        $parsed = $provider->parseFeedback($request);
        if (!$parsed->isValid()) {
            return [
                'ok' => false,
                'http_status' => 422,
                'message' => $parsed->getMessage() !== ''
                    ? $parsed->getMessage()
                    : (string) __('物流反馈解析失败'),
            ];
        }

        $eventId = $parsed->getEventId() !== '' ? $parsed->getEventId() : ('evt-' . hash('sha256', $rawBody));
        $payloadHash = hash('sha256', $rawBody);

        /** @var OrderTrackingFeedbackInbox $inbox */
        $inbox = $this->objectManager->getInstance(OrderTrackingFeedbackInbox::class, [], false);
        $existing = $this->objectManager->getInstance(OrderTrackingFeedbackInbox::class, [], false);
        $existing->clear()
            ->where(OrderTrackingFeedbackInbox::schema_fields_PROVIDER_CODE, $provider->getProviderCode())
            ->where(OrderTrackingFeedbackInbox::schema_fields_METHOD_CODE, $methodCode)
            ->where(OrderTrackingFeedbackInbox::schema_fields_EVENT_ID, $eventId)
            ->find()
            ->fetch();
        if ((int) $existing->getId() > 0) {
            $existingHash = (string) $existing->getData(OrderTrackingFeedbackInbox::schema_fields_PAYLOAD_HASH);
            if ($existingHash !== '' && !hash_equals($existingHash, $payloadHash)) {
                return [
                    'ok' => false,
                    'http_status' => 409,
                    'message' => (string) __('同源事件载荷冲突'),
                    'inbox_code' => (string) $existing->getData(OrderTrackingFeedbackInbox::schema_fields_INBOX_CODE),
                ];
            }

            return [
                'ok' => true,
                'http_status' => 200,
                'duplicate' => true,
                'inbox_code' => (string) $existing->getData(OrderTrackingFeedbackInbox::schema_fields_INBOX_CODE),
                'message' => (string) __('物流反馈已接收（幂等）'),
            ];
        }

        $inboxCode = 'otfi_' . bin2hex(random_bytes(12));
        $inbox->reset();
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_INBOX_CODE, $inboxCode);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_ENDPOINT_CODE, $endpointCode);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_PROVIDER_CODE, $provider->getProviderCode());
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_METHOD_CODE, $methodCode);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_EVENT_ID, $eventId);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_EVENT_TYPE, $parsed->getEventType());
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_ORDER_NUMBER, $parsed->getOrderNumber());
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_TRACKING_NUMBER, $parsed->getTrackingNumber());
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_SUGGESTED_STATUS, $parsed->getSuggestedStatus());
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_STAGE_CODE, $parsed->getStageCode());
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_STATUS, OrderTrackingFeedbackInbox::STATUS_RECEIVED);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_PAYLOAD_HASH, $payloadHash);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_RAW_BODY, $rawBody);
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_PARSED_JSON, json_encode($parsed->getData(), JSON_UNESCAPED_UNICODE));
        $inbox->setData(OrderTrackingFeedbackInbox::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        $inbox->save();

        $eventData = [
            'inbox' => $inbox,
            'inbox_code' => $inboxCode,
            'provider' => $provider,
            'parsed' => $parsed,
        ];
        $this->eventsManager->dispatch('Weline_Order::order_tracking_feedback_received', $eventData);

        return [
            'ok' => true,
            'http_status' => 200,
            'inbox_code' => $inboxCode,
            'message' => (string) __('物流反馈已写入 Inbox'),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseEndpointCode(string $endpointCode): array
    {
        // method.env.default
        $parts = explode('.', $endpointCode);
        $method = trim((string) ($parts[0] ?? ''));
        $env = trim((string) ($parts[1] ?? 'sandbox'));

        return [$method, $env !== '' ? $env : 'sandbox'];
    }
}
