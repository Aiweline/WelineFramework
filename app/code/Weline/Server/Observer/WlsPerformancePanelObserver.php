<?php

declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Server\Service\WlsPerformanceTraceStore;

class WlsPerformancePanelObserver implements ObserverInterface
{
    public function __construct(
        private readonly Request $request,
        private readonly ?WlsPerformanceTraceStore $store = null
    ) {
    }

    public function execute(Event &$event): void
    {
        if (!$this->isPanelAllowed()) {
            return;
        }

        $payload = $this->resolveTelemetryPayload($event);
        if ($payload === null) {
            return;
        }

        $requestId = $this->requestId();
        $this->setRequestIdHeader($requestId);
        $this->store()->record($payload, ['request_id' => $requestId]);
    }

    public function isPanelAllowed(): bool
    {
        return (new PanelAccessService())->canAccessApi($this->request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTelemetryPayload(Event $event): ?array
    {
        $data = $event->getData('data');
        if (\is_array($data)) {
            return $data;
        }

        return null;
    }

    private function requestId(): string
    {
        try {
            return RequestLifecycleTrace::ensureRequestId();
        } catch (\Throwable) {
            return 'wls-' . \bin2hex(\random_bytes(8));
        }
    }

    private function setRequestIdHeader(string $requestId): void
    {
        try {
            $this->request->getResponse()->setHeader('X-Weline-Request-Id', $requestId);
        } catch (\Throwable) {
        }
    }

    private function store(): WlsPerformanceTraceStore
    {
        return $this->store ?? new WlsPerformanceTraceStore();
    }
}
