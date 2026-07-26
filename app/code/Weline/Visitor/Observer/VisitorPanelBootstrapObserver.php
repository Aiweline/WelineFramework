<?php

declare(strict_types=1);

namespace Weline\Visitor\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Visitor\Service\VisitorPanelBootstrapHtmlService;

/**
 * 全局注入开发面板「访问事件」Tab，与 DevToolPanelObserver 同链路。
 *
 * 不依赖主题 body-end，也不由 PageBuilder / PixelBootstrap 代管。
 */
class VisitorPanelBootstrapObserver implements ObserverInterface
{
    private const MARKER = 'data-weline-panel-visitor-bootstrap';

    public function __construct(
        private readonly Request $request
    ) {
    }

    public function execute(Event &$event): void
    {
        if ($event->getName() === 'Weline_Framework::App::run_after' && Runtime::isPersistent()) {
            return;
        }

        $payload = $this->resolvePayload($event);
        if ($payload === null) {
            return;
        }

        $result = $payload['result'] ?? '';
        if (!\is_string($result) || $result === '') {
            return;
        }

        if ($this->request->isAjax()
            || $this->request->isApiFrontend()
            || $this->request->isApiBackend()
            || $this->request->isIframe()
        ) {
            return;
        }

        if (RequestLifecycleTrace::shouldSkipForCurrentRequest()) {
            return;
        }

        if (!$this->isHtmlResponse($result)) {
            return;
        }

        if (\stripos($result, self::MARKER) !== false) {
            return;
        }

        try {
            /** @var VisitorPanelBootstrapHtmlService $bootstrap */
            $bootstrap = ObjectManager::getInstance(VisitorPanelBootstrapHtmlService::class);
            $panelHtml = $bootstrap->render();
        } catch (\Throwable) {
            return;
        }

        if ($panelHtml === '') {
            return;
        }

        $payload['result'] = $this->appendBeforeBodyClose($result, $panelHtml);
        $this->writeBackPayload($event, $payload);
    }

    /**
     * @return array{result: string, trace?: array<string, mixed>}|null
     */
    private function resolvePayload(Event $event): ?array
    {
        $telemetryData = $event->getData('data');
        if (\is_array($telemetryData)) {
            // DevToolPanel 等同链路观察者会把已改 HTML 写到顶层 result；
            // 这里优先接力，避免仍基于未注入面板的 data.result 覆盖。
            $topResult = $event->getData('result');
            if (\is_string($topResult) && $topResult !== '') {
                $telemetryData['result'] = $topResult;
            }

            return $telemetryData;
        }

        $legacyResult = $event->getData('result');
        if (\is_string($legacyResult)) {
            return [
                'result' => $legacyResult,
                'trace' => ['spans' => []],
            ];
        }

        return null;
    }

    /**
     * @param array{result: string, trace?: array<string, mixed>} $payload
     */
    private function writeBackPayload(Event $event, array $payload): void
    {
        $html = (string)($payload['result'] ?? '');
        $event->setData('result', $html);

        $data = $event->getData('data');
        if (\is_array($data)) {
            $data['result'] = $html;
            $event->setData('data', $data);
        }
    }

    private function appendBeforeBodyClose(string $result, string $html): string
    {
        $bodyClosePos = \strripos($result, '</body>');
        if ($bodyClosePos !== false) {
            return \substr($result, 0, $bodyClosePos) . $html . \substr($result, $bodyClosePos);
        }

        return $result . $html;
    }

    private function isHtmlResponse(string $output): bool
    {
        $trimmed = \ltrim($output);
        if ($trimmed === '') {
            return false;
        }

        $head = \strtolower(\substr($trimmed, 0, 32));
        if (\str_starts_with($head, '<!doctype html')
            || \str_starts_with($head, '<html')
            || \str_contains($head, '<head')
            || \str_contains($head, '<body')
        ) {
            return true;
        }

        return \stripos($output, '</html>') !== false || \stripos($output, '</body>') !== false;
    }
}
