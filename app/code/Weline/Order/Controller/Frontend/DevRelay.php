<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\Tracking\OrderTrackingDevRelayStore;
use Weline\Order\Service\Tracking\OrderTrackingFeedbackReceiver;

/**
 * 开发环境物流反馈中继（精简版，对齐 Payment DevRelay 思路）。
 */
class DevRelay extends FrontendController
{
    public function probe(): string
    {
        if (!$this->isDevRelayEnabled()) {
            return $this->jsonResponse(['success' => false, 'message' => (string) __('开发物流中继未启用')], 403);
        }

        $endpointCode = trim((string) $this->request->getParam('endpoint_code', 'fake_carrier.sandbox.default'));
        $payload = [
            'event_id' => 'probe-' . bin2hex(random_bytes(6)),
            'event_type' => 'tracking.probe',
            'order_number' => (string) $this->request->getParam('order_number', ''),
            'tracking_number' => (string) $this->request->getParam('tracking_number', 'FAKE-PROBE'),
            'status' => 'in_transit',
            'stage_code' => 'in_transit',
            'summary' => (string) __('【演示】开发中继探针'),
            'nodes' => [
                ['code' => 'in_transit', 'time' => date('c'), 'text' => (string) __('【演示】中继探针节点')],
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';

        /** @var OrderTrackingFeedbackReceiver $receiver */
        $receiver = ObjectManager::getInstance(OrderTrackingFeedbackReceiver::class);
        $result = $receiver->receive(
            $endpointCode,
            $rawBody,
            ['x-weline-fake-tracking' => 'dev'],
            ['endpoint_code' => $endpointCode, 'probe' => '1'],
        );

        /** @var OrderTrackingDevRelayStore $store */
        $store = ObjectManager::getInstance(OrderTrackingDevRelayStore::class);
        $store->append([
            'at' => date('c'),
            'endpoint_code' => $endpointCode,
            'result' => $result,
            'payload' => $payload,
        ]);

        return $this->jsonResponse([
            'success' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'inbox_code' => $result['inbox_code'] ?? null,
            'notify_url' => $this->getUrl('*/frontend/tracking-callback/notify') . '?endpoint_code=' . rawurlencode($endpointCode),
        ], (int) ($result['http_status'] ?? 500));
    }

    public function events(): string
    {
        if (!$this->isDevRelayEnabled()) {
            return $this->jsonResponse(['success' => false, 'message' => (string) __('开发物流中继未启用')], 403);
        }

        /** @var OrderTrackingDevRelayStore $store */
        $store = ObjectManager::getInstance(OrderTrackingDevRelayStore::class);

        return $this->jsonResponse([
            'success' => true,
            'events' => $store->listRecent(50),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $status = 200): string
    {
        $this->request->getResponse()->setHttpResponseCode($status);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function isDevRelayEnabled(): bool
    {
        if (\defined('DEV') && DEV) {
            return true;
        }

        $env = (string) (getenv('WELINE_ENV') ?: ($_ENV['WELINE_ENV'] ?? 'PROD'));
        if (strtoupper($env) === 'DEV') {
            return true;
        }

        $settings = BP . 'var/order-tracking-dev-relay-settings.json';
        if (!is_file($settings)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($settings), true);

        return is_array($decoded) && !empty($decoded['enabled']);
    }
}
