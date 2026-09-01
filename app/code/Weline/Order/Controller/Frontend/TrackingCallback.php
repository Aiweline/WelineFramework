<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\Tracking\OrderTrackingFeedbackReceiver;

/**
 * 统一物流反馈入口（对齐 payment/frontend/callback/notify）。
 */
class TrackingCallback extends FrontendController
{
    public function notify(): string
    {
        $endpointCode = trim((string) $this->request->getParam('endpoint_code', ''));
        $rawBody = $this->rawBody();
        if ($rawBody === '') {
            $bodyParams = $this->request->getBodyParams(true);
            if (is_array($bodyParams) && $bodyParams !== []) {
                $rawBody = json_encode($bodyParams, JSON_UNESCAPED_UNICODE) ?: '';
            }
        }

        /** @var OrderTrackingFeedbackReceiver $receiver */
        $receiver = ObjectManager::getInstance(OrderTrackingFeedbackReceiver::class);
        $result = $receiver->receive(
            $endpointCode,
            $rawBody,
            $this->collectHeaders(),
            (array) $this->request->getParams(),
        );

        return $this->jsonResponse([
            'success' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'inbox_code' => $result['inbox_code'] ?? null,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ], (int) ($result['http_status'] ?? 500));
    }

    private function rawBody(): string
    {
        if (method_exists($this->request, 'getRawBody')) {
            return (string) $this->request->getRawBody();
        }
        if (method_exists($this->request, 'getParameterBag')) {
            return (string) $this->request->getParameterBag()->getRawBody();
        }

        return (string) file_get_contents('php://input');
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

    /**
     * @return array<string, string>
     */
    private function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = is_scalar($value) ? (string) $value : '';
        }

        return $headers;
    }
}
