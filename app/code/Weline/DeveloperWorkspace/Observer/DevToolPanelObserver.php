<?php

declare(strict_types=1);

/*
 * This file was authored by Qiuyefei and belongs to Aiweline.
 * Email: aiweline@qq.com
 * Website: aiweline.com
 * Forum: https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Observer;

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
    private const PERSISTENT_MAX_RESPONSE_BYTES = 262144;

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function execute(Event &$event): void
    {
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

        if (Runtime::isPersistent() && !(bool)Env::get('wls.debug.dev_tool_panel', false)) {
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

            if (!$this->isHtmlResponse($result)) {
                return;
            }

            if ($this->shouldSkipForResponseSize($result)) {
                return;
            }

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

            $traceSpans = $this->resolveCurrentTraceSpans($payload);
            if (!empty($traceSpans)) {
                $payload['trace']['spans'] = $traceSpans;
                $result = $this->injectTraceScript($result, $traceSpans);
            }

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

    private function injectTraceScript(string $result, array $traceSpans): string
    {
        $traceScript = '<script>window.__WELINE_REQUEST_TRACE__=' . json_encode($traceSpans) . ';</script>';
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
            return $this->filterTraceSpansForPayload($traceSpans);
        }

        $payloadTraceSpans = $payload['trace']['spans'] ?? [];
        if (!is_array($payloadTraceSpans)) {
            return [];
        }

        return $this->filterTraceSpansForPayload($payloadTraceSpans);
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

            return is_string($html) ? $html : '';
        } catch (\Exception $e) {
            $this->logToConsole('error', 'DevToolPanel Render Error: ' . $e->getMessage());
            return '';
        }
    }

    private function isHtmlResponse(string $output): bool
    {
        $trimmed = trim($output);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return false;
            }
        }

        if (strlen($trimmed) < 100 &&
            stripos($trimmed, '<html') === false &&
            stripos($trimmed, '<!doctype') === false &&
            stripos($trimmed, '<body') === false) {
            return false;
        }

        return stripos($output, '<html') !== false ||
            stripos($output, '<!doctype') !== false ||
            stripos($output, '<body') !== false;
    }

    private function shouldSkipForResponseSize(string $result): bool
    {
        if (!Runtime::isPersistent()) {
            return false;
        }

        $limit = (int)Env::get('dev_tool.max_response_bytes', self::PERSISTENT_MAX_RESPONSE_BYTES);
        if ($limit <= 0) {
            return false;
        }

        return \strlen($result) > $limit;
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
