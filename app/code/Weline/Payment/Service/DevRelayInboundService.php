<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\Webhook\WebhookReceiveResult;

final class DevRelayInboundService
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
        private readonly PaymentCallbackReceiver $receiver,
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function replay(
        string $sessionCode,
        string $token,
        string $endpointCode,
        string $rawBody,
        array $headers = [],
        ?string $signature = null,
    ): WebhookReceiveResult {
        if (!$this->gate->canOpenUi()) {
            throw new \RuntimeException((string) __('Dev Relay 未启用。'));
        }

        $this->sessions->requireActiveSession($sessionCode, $token, null);

        $endpoint = trim($endpointCode);
        // 连调探针：不依赖真实 webhook endpoint / 验签，仅确认 inbound 通路。
        if ($endpoint === 'devrelay.probe' || str_starts_with($endpoint, 'devrelay.probe.')) {
            return new WebhookReceiveResult(
                httpStatus: WebhookReceiveResult::HTTP_OK,
                body: json_encode([
                    'success' => true,
                    'probe' => true,
                    'endpoint_code' => $endpoint,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"success":true}',
                errorCode: null,
                inboxCode: null,
                inboxWritten: false,
                replayed: true,
            );
        }

        return $this->receiver->receive(
            $endpoint,
            $rawBody,
            $headers,
            [],
            $signature,
        );
    }
}
