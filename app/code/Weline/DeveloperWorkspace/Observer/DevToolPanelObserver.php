<?php

declare(strict_types=1);

/*
 * This file was authored by Qiuyefei and belongs to Aiweline.
 * Email: aiweline@qq.com
 * Website: aiweline.com
 * Forum: https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Observer;

use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\HookInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\View\Template;

/**
 * Injects the developer tool panel and request trace into HTML responses.
 */
class DevToolPanelObserver implements ObserverInterface
{
    private const DEFAULT_MAX_RESPONSE_BYTES = 1048576;
    private const MAX_TRACE_SPANS = 300;
    private const MAX_TRACE_META_FIELD_BYTES = 2048;
    /** 内联注入页面的 trace JSON 默认上限（字节），防止超大 JSON 撑爆 Worker 内存 */
    private const DEFAULT_MAX_TRACE_JSON_BYTES = 524288;
    private const TRACE_TTL_SECONDS = 60;
    private const PANEL_HTML_CACHE_TTL = 300.0;

    /** @var array<string, array{expires_at: float, html: string}> */
    private static array $panelHtmlCache = [];

    private Request $request;
    private DevToolPayloadStore $payloadStore;

    public function __construct(Request $request, ?DevToolPayloadStore $payloadStore = null)
    {
        $this->request = $request;
        $this->payloadStore = $payloadStore ?? new DevToolPayloadStore();
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

        $enableInProd = Env::get('dev_tool.enable_in_prod', false);
        $devToolKey = Env::get('dev_tool.key', 'dev_tool');
        $devToolCookieName = Env::get('dev_tool.cookie_name', 'w_dev_tool');
        $devToolSecret = Env::get('dev_tool.secret', '');

        $urlParam = $this->request->getGet($devToolKey);
        if (!empty($urlParam)) {
            if (!empty($devToolSecret)) {
                if ($urlParam === $devToolSecret) {
                    Cookie::set($devToolCookieName, '1', 3600 * 24 * 30, ['path' => '/']);
                } else {
                    return;
                }
            } else {
                Cookie::set($devToolCookieName, '1', 3600 * 24 * 30, ['path' => '/']);
            }
        }

        $cookieValue = Cookie::get($devToolCookieName);
        $hasCookie = !empty($cookieValue) && $cookieValue === '1';

        if (!DEV && !$enableInProd && !$hasCookie) {
            return;
        }

        if ($this->request->isAjax() ||
            $this->request->isApiFrontend() ||
            $this->request->isApiBackend()) {
            return;
        }

        if ($this->request->isIframe()) {
            return;
        }

        $allowPersistentDevToolPanel = (bool)Env::get('wls.debug.dev_tool_panel', DEV);
        if (Runtime::isPersistent() && !$allowPersistentDevToolPanel) {
            return;
        }

        if (RequestLifecycleTrace::shouldSkipForCurrentRequest()) {
            return;
        }

        try {
            $result = $payload['result'] ?? '';
            if (empty($result) || !is_string($result)) {
                return;
            }

            $requestId = RequestLifecycleTrace::ensureRequestId();
            $this->setRequestIdHeader($requestId);

            if (!$this->isHtmlResponse($result)) {
                return;
            }

            if ($this->shouldSkipForResponseSize($result)) {
                return;
            }

            $existingRequestIds = $this->extractRequestIdsFromResult($result);
            $panelAlreadyInjected = stripos($result, 'id="dev-tool-panel"') !== false;
            if (!$panelAlreadyInjected) {
                $panelTraceStart = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
                if ($panelTraceStart > 0) {
                    RequestLifecycleTrace::pushCurrentParent('dev_tool_panel');
                }

                try {
                    $panelHtml = $this->measureTraceStage(
                        'dev_tool_panel::render_panel',
                        fn() => $this->renderPanel()
                    );

                    if ($panelHtml !== '') {
                        $result = $this->measureTraceStage(
                            'dev_tool_panel::inject_html',
                            fn() => $this->appendPanelHtml($result, $panelHtml)
                        );
                    }
                } finally {
                    if ($panelTraceStart > 0) {
                        RequestLifecycleTrace::popCurrentParent();
                        RequestLifecycleTrace::recordSpan(
                            'dev_tool_panel',
                            (microtime(true) - $panelTraceStart) * 1000,
                            'developer'
                        );
                    }
                }
            }

            $this->storeTracePayload($requestId);
            foreach ($existingRequestIds as $existingRequestId) {
                if ($existingRequestId !== $requestId) {
                    $this->storeTracePayload($existingRequestId);
                }
            }
            $result = $this->injectRequestMetaScript($result, $requestId);

            $payload['result'] = $result;
            $this->writeBackPayload($event, $payload);
        } catch (\Exception $e) {
            $this->logToConsole('error', 'DevToolPanel Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Supports both:
     * 1) Weline_Framework::telemetry::request_collected => ['data' => [...]]
     * 2) Weline_Framework::App::run_after => ['result' => '...']
     */
    private function resolvePayload(Event $event): ?array
    {
        $telemetryData = $event->getData('data');
        if (is_array($telemetryData)) {
            return $telemetryData;
        }

        $legacyResult = $event->getData('result');
        if (is_string($legacyResult)) {
            return [
                'result' => $legacyResult,
                'trace' => ['spans' => []],
            ];
        }

        return null;
    }

    private function writeBackPayload(Event $event, array $payload): void
    {
        if (is_array($event->getData('data'))) {
            if (array_key_exists('result', $payload)) {
                $event->setData('result', (string)($payload['result'] ?? ''));
            }
            if (array_key_exists('trace', $payload) && is_array($payload['trace'])) {
                $event->setData('trace', $payload['trace']);
            }
            return;
        }

        $event->setData('result', (string)($payload['result'] ?? ''));
    }

    private function setRequestIdHeader(string $requestId): void
    {
        try {
            $this->request->getResponse()->setHeader('X-Weline-Request-Id', $requestId);
        } catch (\Throwable) {
        }
    }

    private function storeTracePayload(string $requestId): void
    {
        try {
            $payload = RequestLifecycleTrace::exportCompactPayload();
            if ((int)($payload['summary']['span_count'] ?? 0) <= 0) {
                return;
            }
            $stored = $this->payloadStore->set('trace', 'trace:' . $requestId, $payload, self::TRACE_TTL_SECONDS);
            if (!$stored) {
                $this->logToConsole('debug', 'DevToolPanel trace store skipped: unable to persist payload', [
                    'request_id' => $requestId,
                    'span_count' => (int)($payload['summary']['span_count'] ?? 0),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logToConsole('debug', 'DevToolPanel trace store skipped: ' . $e->getMessage());
        }
    }

    private function injectRequestMetaScript(string $result, string $requestId): string
    {
        $restBackendPrefix = (string)($_SERVER['WELINE_REST_BACKEND_PREFIX'] ?? '');
        if ($restBackendPrefix === '') {
            $restBackendPrefix = (string)(Env::getAreaRoutePrefix('rest_backend') ?? '');
        }
        if ($restBackendPrefix === '') {
            $envConfig = \is_file(BP . 'app' . DS . 'etc' . DS . 'env.php')
                ? (include BP . 'app' . DS . 'etc' . DS . 'env.php')
                : [];
            if (\is_array($envConfig)) {
                $restBackendPrefix = (string)($envConfig['router']['area_routes']['rest_backend']['prefix'] ?? '');
            }
        }
        $apiBase = ($restBackendPrefix !== '' ? trim($restBackendPrefix, '/') . '/' : '') . 'dev/tool/rest/v1';
        $config = [
            'requestId' => $requestId,
            'traceTtl' => self::TRACE_TTL_SECONDS,
            'apiBase' => $apiBase,
        ];
        $json = \json_encode($config, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json)) {
            $json = '{}';
        }
        $script = '<script>window.__WELINE_REQUEST_ID__='
            . \json_encode($requestId, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            . ';window.__WELINE_DEV_TOOL__=' . $json . ';</script>';

        if (stripos($result, 'window.__WELINE_REQUEST_ID__=') !== false) {
            $count = 0;
            $updated = preg_replace(
                '/<script>\s*window\.__WELINE_REQUEST_ID__=.*?<\/script>/is',
                $script,
                $result,
                1,
                $count
            );
            if ($count > 0 && is_string($updated)) {
                return $updated;
            }
        }

        $bodyClosePos = strripos($result, '</body>');
        if ($bodyClosePos !== false) {
            return substr($result, 0, $bodyClosePos) . $script . substr($result, $bodyClosePos);
        }

        return $result . $script;
    }

    /**
     * @return string[]
     */
    private function extractRequestIdsFromResult(string $result): array
    {
        $requestIds = [];
        if (preg_match_all('/window\.__WELINE_REQUEST_ID__\s*=\s*["\']([^"\']+)["\']/', $result, $matches)) {
            foreach ($matches[1] as $requestId) {
                $requestIds[(string)$requestId] = true;
            }
        }

        if (preg_match_all('/"requestId"\s*:\s*"([^"]+)"/', $result, $matches)) {
            foreach ($matches[1] as $requestId) {
                $requestIds[(string)$requestId] = true;
            }
        }

        return array_values(array_filter(array_keys($requestIds), static function (string $requestId): bool {
            return $requestId !== '' && preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $requestId) === 1;
        }));
    }

    private function injectTraceScript(string $result, array $traceSpans): string
    {
        $traceScript = '<script>window.__WELINE_REQUEST_TRACE__=' . $this->buildTraceInlineJson($traceSpans) . ';</script>';
        if (stripos($result, 'window.__WELINE_REQUEST_TRACE__=') !== false) {
            $count = 0;
            $updated = preg_replace(
                '/<script>\s*window\.__WELINE_REQUEST_TRACE__=.*?<\/script>/is',
                $traceScript,
                $result,
                1,
                $count
            );
            if ($count > 0 && is_string($updated)) {
                return $updated;
            }
        }

        $bodyClosePos = strripos($result, '</body>');
        if ($bodyClosePos !== false) {
            $before = substr($result, 0, $bodyClosePos);
            $after = substr($result, $bodyClosePos);
            return $before . $traceScript . $after;
        }

        return $result . $traceScript;
    }

    /**
     * 将 trace 序列化为可嵌入 HTML 的 JSON；超长时降级为占位 span，避免 json_encode 与字符串拼接 OOM。
     *
     * @param array<int, array<string, mixed>> $traceSpans
     */
    private function buildTraceInlineJson(array $traceSpans): string
    {
        $flags = JSON_UNESCAPED_UNICODE;
        if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= \JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $encoded = \json_encode($traceSpans, $flags);
        if (!\is_string($encoded)) {
            $encoded = '[]';
        }

        $maxBytes = (int)Env::get('dev_tool.max_trace_json_bytes', self::DEFAULT_MAX_TRACE_JSON_BYTES);
        if ($maxBytes > 0 && \strlen($encoded) > $maxBytes) {
            $stub = [
                [
                    'name' => 'dev_tool_trace_json_truncated',
                    'duration_ms' => 0.0,
                    'category' => 'framework',
                    'meta' => [
                        'bytes' => \strlen($encoded),
                        'limit' => $maxBytes,
                        'hint' => 'dev_tool.max_trace_json_bytes',
                    ],
                ],
            ];
            $encoded = \json_encode($stub, $flags);
            if (!\is_string($encoded)) {
                $encoded = '[]';
            }
        }

        return $encoded;
    }

    private function appendPanelHtml(string $result, string $panelHtml): string
    {
        $bodyClosePos = strripos($result, '</body>');
        if ($bodyClosePos !== false) {
            $before = substr($result, 0, $bodyClosePos);
            $after = substr($result, $bodyClosePos);
            return $before . $panelHtml . $after;
        }

        return $result . $panelHtml;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function resolveCurrentTraceSpans(array $payload): array
    {
        $traceSpans = RequestLifecycleTrace::getSpansWithDbSummary();
        if (!empty($traceSpans)) {
            return $this->normalizeTraceSpansForPayload(
                $this->filterTraceSpansForPayload($traceSpans)
            );
        }

        $payloadTraceSpans = $payload['trace']['spans'] ?? [];
        if (!is_array($payloadTraceSpans)) {
            return [];
        }

        return $this->normalizeTraceSpansForPayload(
            $this->filterTraceSpansForPayload($payloadTraceSpans)
        );
    }

    private function measureTraceStage(string $spanName, callable $callback): mixed
    {
        $spanStart = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;

        try {
            return $callback();
        } finally {
            if ($spanStart > 0) {
                RequestLifecycleTrace::recordSpan(
                    $spanName,
                    (microtime(true) - $spanStart) * 1000,
                    'developer'
                );
            }
        }
    }

    /**
     * Keep the panel summary spans but trim nested descendants generated while
     * rendering the panel so the inline payload does not grow recursively.
     *
     * @param array<int, array<string, mixed>> $traceSpans
     * @return array<int, array<string, mixed>>
     */
    private function filterTraceSpansForPayload(array $traceSpans): array
    {
        $filtered = [];
        $excludedDescendants = [];

        foreach ($traceSpans as $span) {
            $name = (string)($span['name'] ?? '');
            $parent = (string)($span['parent'] ?? '');

            if ($name !== '' && str_starts_with($name, 'dev_tool_panel')) {
                $filtered[] = $span;
                continue;
            }

            if ($parent === 'dev_tool_panel' || isset($excludedDescendants[$parent])) {
                if ($name !== '') {
                    $excludedDescendants[$name] = true;
                }
                continue;
            }

            $filtered[] = $span;
        }

        return $filtered;
    }

    private function renderPanel(): string
    {
        try {
            $templatePath = dirname(__DIR__) . '/view/hooks/dev-tool-panel.phtml';
            if (!is_file($templatePath)) {
                $this->logToConsole('error', 'DevToolPanel: Template file not found: ' . $templatePath);
                return '';
            }

            $isBackend = $this->request->isBackend();
            $devToolCookieName = Env::get('dev_tool.cookie_name', 'w_dev_tool');
            $hasCookie = !empty(Cookie::get($devToolCookieName)) && Cookie::get($devToolCookieName) === '1';
            $cacheKey = sha1(json_encode([
                'backend' => $isBackend,
                'cookie' => $hasCookie,
                'base_url' => (string)$this->request->getBaseUrl(),
            ], JSON_UNESCAPED_SLASHES) ?: 'dev-tool-panel');
            $cached = self::$panelHtmlCache[$cacheKey] ?? null;
            if (is_array($cached)
                && isset($cached['expires_at'], $cached['html'])
                && (float)$cached['expires_at'] >= microtime(true)
                && is_string($cached['html'])) {
                return $cached['html'];
            }
            try {
                $sharedCached = $this->payloadStore->get('panel', 'html:' . $cacheKey);
                if (is_string($sharedCached)) {
                    self::$panelHtmlCache[$cacheKey] = [
                        'expires_at' => microtime(true) + self::PANEL_HTML_CACHE_TTL,
                        'html' => $sharedCached,
                    ];
                    return $sharedCached;
                }
            } catch (\Throwable) {
                // Panel cache is an optimization only.
            }

            $extraTabsHtml = '';
            $extraSearchAreasHtml = '';
            try {
                $template = Template::getInstance();
                $extraTabsHtml = $template->getHook(HookInterface::DEVELOPER_WORKSPACE_DEVTOOL_PANEL_TABS_AFTER);
                $extraSearchAreasHtml = $template->getHook(HookInterface::DEVELOPER_WORKSPACE_DEVTOOL_PANEL_SEARCH_AREAS_AFTER);
            } catch (\Throwable $e) {
                // Missing hook registrations must not break the panel.
            }

            ob_start();
            $panelType = $isBackend ? 'backend' : 'frontend';
            $showCloseButton = $hasCookie;
            $devToolCookieNameJs = $devToolCookieName;
            include $templatePath;
            $html = ob_get_clean();

            $html = is_string($html) ? $html : '';
            if ($html !== '') {
                if (count(self::$panelHtmlCache) > 16) {
                    self::$panelHtmlCache = [];
                }
                self::$panelHtmlCache[$cacheKey] = [
                    'expires_at' => microtime(true) + self::PANEL_HTML_CACHE_TTL,
                    'html' => $html,
                ];
                try {
                    $this->payloadStore->set('panel', 'html:' . $cacheKey, $html, (int)self::PANEL_HTML_CACHE_TTL);
                } catch (\Throwable) {
                    // Panel cache is an optimization only.
                }
            }

            return $html;
        } catch (\Exception $e) {
            $this->logToConsole('error', 'DevToolPanel Render Error: ' . $e->getMessage());
            return '';
        }
    }

    private function isHtmlResponse(string $output): bool
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return false;
        }

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return false;
            }
        }

        if (stripos($trimmed, '<?xml') === 0) {
            return false;
        }

        return stripos($output, '<html') !== false ||
            stripos($output, '<!doctype') !== false ||
            stripos($output, '<body') !== false ||
            preg_match('/<(?:div|span|p|a|img|table|form|ul|ol|li|section|article|main|header|footer|nav|h[1-6]|script|style)\b/i', $output) === 1;
    }

    private function shouldSkipForResponseSize(string $result): bool
    {
        $limit = (int)Env::get('dev_tool.max_response_bytes', self::DEFAULT_MAX_RESPONSE_BYTES);
        if ($limit <= 0) {
            return false;
        }

        return \strlen($result) > $limit;
    }

    /**
     * @param array<int, array<string, mixed>> $traceSpans
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTraceSpansForPayload(array $traceSpans): array
    {
        if ($traceSpans === []) {
            return [];
        }

        $limit = $this->maxTraceSpansForPayload();
        $limited = \array_slice($traceSpans, -$limit);
        $maxMetaField = $this->maxTraceMetaFieldBytesForPayload();
        foreach ($limited as &$span) {
            if (!\is_array($span)) {
                continue;
            }

            if ($maxMetaField > 0 && isset($span['meta']) && \is_array($span['meta'])) {
                foreach ($span['meta'] as $key => $value) {
                    if (!\is_scalar($value) && $value !== null) {
                        continue;
                    }
                    $stringValue = (string)$value;
                    if (\strlen($stringValue) <= $maxMetaField) {
                        continue;
                    }
                    $span['meta'][$key] = \substr($stringValue, 0, $maxMetaField) . '...(truncated)';
                }
            }
        }
        unset($span);

        return $limited;
    }

    private function maxTraceSpansForPayload(): int
    {
        $v = (int)Env::get('dev_tool.max_trace_spans', self::MAX_TRACE_SPANS);
        if ($v <= 0) {
            return self::MAX_TRACE_SPANS;
        }

        return \min($v, 5000);
    }

    /**
     * 0 表示不截断 meta（便于复制完整 SQL）；>0 为单字段最大字节；<0 回退为 MAX_TRACE_META_FIELD_BYTES。
     */
    private function maxTraceMetaFieldBytesForPayload(): int
    {
        $v = (int)Env::get('dev_tool.max_trace_meta_field_bytes', 0);
        if ($v < 0) {
            return self::MAX_TRACE_META_FIELD_BYTES;
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function logToConsole(string $level, string $message, array $data = []): void
    {
        $level = in_array($level, ['error', 'warn', 'info', 'log'], true) ? $level : 'log';

        $output = '<script>';
        $output .= "console.{$level}('[DevToolPanel] " . addslashes($message) . "');";

        if (!empty($data)) {
            $output .= "console.{$level}('[DevToolPanel] Details:', " . json_encode($data, JSON_UNESCAPED_UNICODE) . ");";
        }

        $output .= '</script>';

        echo $output;
    }
}
