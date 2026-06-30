<?php

declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Server\Service\WlsPerformanceTraceStore;

class WlsPerformancePanelObserver implements ObserverInterface
{
    private const DEFAULT_MAX_RESPONSE_BYTES = 1048576;
    private const ASSET_VERSION = '20260629-wls-performance-panel-4';

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

        $result = $payload['result'] ?? '';
        if (!\is_string($result) || $result === '') {
            return;
        }
        if (!$this->canInjectIntoCurrentResponse($result)) {
            return;
        }
        if (\stripos($result, 'data-weline-wls-performance-bootstrap') !== false) {
            return;
        }

        $payload['result'] = $this->appendHtml($result, $this->renderBootstrap($requestId));
        $this->writeBackPayload($event, $payload);
    }

    public function isPanelAllowed(): bool
    {
        if ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) {
            return true;
        }
        if ((bool)Env::get('wls.debug.performance_panel', false)) {
            return true;
        }
        if ((bool)Env::get('wls.performance_panel.enable_in_prod', false)
            && Cookie::get((string)Env::get('wls.performance_panel.cookie_name', 'w_wls_perf')) === '1'
        ) {
            return true;
        }

        return false;
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

    /**
     * @param array<string, mixed> $payload
     */
    private function writeBackPayload(Event $event, array $payload): void
    {
        if (\array_key_exists('result', $payload)) {
            $event->setData('result', (string)($payload['result'] ?? ''));
        }
    }

    private function canInjectIntoCurrentResponse(string $result): bool
    {
        if ($this->request->isAjax()
            || $this->request->isApiFrontend()
            || $this->request->isApiBackend()
            || $this->request->isIframe()
        ) {
            return false;
        }

        $limit = (int)Env::get('wls.performance_panel.max_response_bytes', self::DEFAULT_MAX_RESPONSE_BYTES);
        if ($limit > 0 && \strlen($result) > $limit) {
            return false;
        }

        return $this->isHtmlResponse($result);
    }

    private function isHtmlResponse(string $output): bool
    {
        $trimmed = \trim($output);
        if ($trimmed === '') {
            return false;
        }
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            \json_decode($trimmed);
            if (\json_last_error() === \JSON_ERROR_NONE) {
                return false;
            }
        }
        if (\stripos($trimmed, '<?xml') === 0) {
            return false;
        }

        return \stripos($output, '<html') !== false
            || \stripos($output, '<!doctype') !== false
            || \stripos($output, '<body') !== false
            || \preg_match('/<(?:main|section|article|div|span|p|a|form|table|ul|ol|script|style)\b/i', $output) === 1;
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

    private function appendHtml(string $result, string $html): string
    {
        $bodyClosePos = \strripos($result, '</body>');
        if ($bodyClosePos !== false) {
            return \substr($result, 0, $bodyClosePos) . $html . \substr($result, $bodyClosePos);
        }

        return $result . $html;
    }

    private function renderBootstrap(string $requestId): string
    {
        $requestIdJson = \json_encode($requestId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!\is_string($requestIdJson)) {
            $requestIdJson = '""';
        }
        $cssUrl = $this->jsonString('/Weline/Server/view/statics/wls-performance-panel/panel.css?v=' . self::ASSET_VERSION);
        $jsUrl = $this->jsonString('/Weline/Server/view/statics/wls-performance-panel/panel.js?v=' . self::ASSET_VERSION);
        $isDev = \defined('DEV') && DEV;
        $apiScriptUrl = $this->jsonString($isDev
            ? '/Weline/Frontend/view/statics/js/weline-api.js?v=' . self::ASSET_VERSION
            : '/static/Weline/Frontend/js/weline-api.js'
        );
        $apiWorkerUrl = $this->jsonString($isDev
            ? '/Weline/Frontend/view/statics/js/weline-api-worker.js?v=' . self::ASSET_VERSION
            : '/static/Weline/Frontend/js/weline-api-worker.js'
        );
        $apiEndpoint = $this->jsonString(Env::getFrontendQueryBinPath());

        return <<<HTML
<script data-no-extract="true" data-load-order="last" data-weline-wls-performance-bootstrap="true">
(function(d,w){"use strict";if(w.__WELINE_WLS_PERFORMANCE_BOOTSTRAPPED__)return;w.__WELINE_WLS_PERFORMANCE_BOOTSTRAPPED__=true;var command="wls";var buffer="";var cssUrl={$cssUrl};var jsUrl={$jsUrl};var requestId={$requestIdJson};var cssLoaded=false;var jsPromise=null;w.__WELINE_WLS_PANEL_CONFIG__=Object.assign({},w.__WELINE_WLS_PANEL_CONFIG__||{},{requestId:requestId,command:command,apiScriptUrl:{$apiScriptUrl},api:{workerUrl:{$apiWorkerUrl},endpoint:{$apiEndpoint},queryBinUrl:{$apiEndpoint},area:"frontend"}});function ignoredTarget(t){if(!t)return false;var tag=(t.tagName||"").toLowerCase();return tag==="input"||tag==="textarea"||tag==="select"||t.isContentEditable===true}function head(n){(d.head||d.documentElement).appendChild(n)}function loadCss(){if(cssLoaded||d.querySelector('link[data-weline-wls-panel="css"]')){cssLoaded=true;return Promise.resolve()}return new Promise(function(resolve,reject){var link=d.createElement("link");link.rel="stylesheet";link.href=cssUrl;link.setAttribute("data-weline-wls-panel","css");link.onload=function(){cssLoaded=true;resolve()};link.onerror=function(){reject(new Error("Failed to load WLS panel CSS"))};head(link)})}function loadJs(){if(w.__WELINE_WLS_PANEL__){return Promise.resolve(w.__WELINE_WLS_PANEL__)}if(jsPromise)return jsPromise;jsPromise=new Promise(function(resolve,reject){var script=d.createElement("script");script.src=jsUrl;script.defer=true;script.setAttribute("data-weline-wls-panel","js");script.onload=function(){w.__WELINE_WLS_PANEL__?resolve(w.__WELINE_WLS_PANEL__):reject(new Error("WLS panel API missing"))};script.onerror=function(){reject(new Error("Failed to load WLS panel JS"))};head(script)});return jsPromise}function openPanel(){loadCss().then(loadJs).then(function(panel){if(panel&&typeof panel.open==="function")panel.open({requestId:requestId})}).catch(function(error){if(w.console&&console.warn)console.warn("[wls-panel]",error)})}w.wlsPanel=openPanel;d.addEventListener("keydown",function(event){if(event.ctrlKey||event.metaKey||event.altKey||event.isComposing||ignoredTarget(event.target))return;if(!event.key||event.key.length!==1)return;buffer=(buffer+event.key.toLowerCase()).slice(-command.length);if(buffer!==command)return;buffer="";openPanel()},true)})(document,window);
</script>
HTML;
    }

    private function jsonString(string $value): string
    {
        $encoded = \json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return \is_string($encoded) ? $encoded : '""';
    }

    private function store(): WlsPerformanceTraceStore
    {
        return $this->store ?? new WlsPerformanceTraceStore();
    }
}
