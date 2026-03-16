<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\HookInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;

/**
 * 开发工具面板 Observer
 * 监听 Weline_Framework::telemetry::request_collected 事件，在页面输出前注入 trace 数据与开发工具面板
 */
class DevToolPanelObserver implements ObserverInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $eventName = (string)$event->getName();
        $this->debugLog('execute_enter', ['event' => $eventName, 'dev' => DEV]);
        $payload = $this->resolvePayload($event);
        if ($payload === null) {
            $this->debugLog('skip_invalid_payload', ['event' => $eventName]);
            return;
        }

        // 检查是否启用开发工具面板
        // 1. 开发模式下默认启用
        // 2. 生产模式下可通过配置 dev_tool.enable_in_prod 启用
        // 3. 通过URL参数和Cookie控制（优先级最高）
        $enableInProd = Env::get('dev_tool.enable_in_prod', false);
        $devToolKey = Env::get('dev_tool.key', 'dev_tool'); // URL参数名，默认 dev_tool
        $devToolCookieName = Env::get('dev_tool.cookie_name', 'w_dev_tool'); // Cookie名，默认 w_dev_tool
        $devToolSecret = Env::get('dev_tool.secret', ''); // 密钥，用于验证URL参数
        
        // 检查URL参数
        $urlParam = $this->request->getGet($devToolKey);
        if (!empty($urlParam)) {
            // 如果配置了密钥，需要验证
            if (!empty($devToolSecret)) {
                if ($urlParam === $devToolSecret) {
                    // 验证通过，设置Cookie（30天有效期）
                    Cookie::set($devToolCookieName, '1', 3600 * 24 * 30, ['path' => '/']);
                } else {
                    // 密钥不匹配，不启用
                    return;
                }
            } else {
                // 未配置密钥，直接设置Cookie
                Cookie::set($devToolCookieName, '1', 3600 * 24 * 30, ['path' => '/']);
            }
        }
        
        // 检查Cookie
        $cookieValue = Cookie::get($devToolCookieName);
        $hasCookie = !empty($cookieValue) && $cookieValue === '1';
        
        // 判断是否显示面板
        // 1. 开发模式：默认显示
        // 2. 生产模式 + 配置启用：显示
        // 3. 有Cookie：显示
        if (!DEV && !$enableInProd && !$hasCookie) {
            $this->debugLog('skip_not_enabled', [
                'dev' => DEV,
                'enableInProd' => $enableInProd,
                'hasCookie' => $hasCookie,
            ]);
            return;
        }

        // 如果是 AJAX 请求或接口请求，不显示面板
        if ($this->request->isAjax() || 
            $this->request->isApiFrontend() || 
            $this->request->isApiBackend()) {
            $this->debugLog('skip_ajax_or_api', [
                'isAjax' => $this->request->isAjax(),
                'isApiFrontend' => $this->request->isApiFrontend(),
                'isApiBackend' => $this->request->isApiBackend(),
            ]);
            return;
        }

        // 如果是 iframe 请求（URL 带 isIframe 或 Sec-Fetch-Dest: iframe），不显示 id="dev-tool-panel"
        if ($this->request->isIframe()) {
            $this->debugLog('skip_iframe');
            return;
        }

        try {
            // 获取页面输出结果（兼容 telemetry / run_after 两种事件）
            $result = $payload['result'] ?? '';
            
            if (empty($result) || !is_string($result)) {
                $this->debugLog('skip_empty_result');
                return;
            }
            
            // 检查是否是 HTML 响应（包含 JSON 检测）
            if (!$this->isHtmlResponse($result)) {
                $this->debugLog('skip_not_html_response');
                return;
            }

            // 先注入请求链路数据（供 dev-tool-panel.phtml 中 trace 视图读取）
            $traceSpans = $payload['trace']['spans'] ?? [];
            if (!is_array($traceSpans) || empty($traceSpans)) {
                // 兜底：兼容 run_after 事件或 payload 未带 trace 的场景，直接读取当前请求追踪数据
                $traceSpans = RequestLifecycleTrace::getSpansWithDbSummary();
                if (is_array($traceSpans) && !empty($traceSpans)) {
                    $payload['trace']['spans'] = $traceSpans;
                    $this->debugLog('trace_fallback_loaded', ['spansCount' => count($traceSpans)]);
                } else {
                    $this->debugLog('trace_empty_after_fallback', ['traceEnabled' => RequestLifecycleTrace::isEnabled()]);
                }
            }
            if (is_array($traceSpans) && !empty($traceSpans)) {
                $dbSpansCount = count(array_filter($traceSpans, static function ($span) {
                    return (($span['category'] ?? '') === 'db');
                }));
                $this->debugLog('trace_stats', [
                    'spansTotal' => count($traceSpans),
                    'dbSpans' => $dbSpansCount,
                    'event' => $eventName,
                ]);
                $result = $this->injectTraceScript($result, $traceSpans);
                $this->debugLog('trace_injected', ['spansCount' => count($traceSpans)]);
            }

            // 幂等保护：避免 telemetry + run_after 双监听导致重复注入同一面板
            // 注意：trace 注入必须先执行，否则会出现“面板显示但无链路数据”
            if (stripos($result, 'id="dev-tool-panel"') !== false) {
                $payload['result'] = $result;
                $this->writeBackPayload($event, $payload);
                $this->debugLog('skip_already_injected');
                return;
            }

            // 渲染开发工具面板
            $panelHtml = $this->renderPanel();
            
            if (empty($panelHtml)) {
                $this->debugLog('skip_empty_panel_html');
                return;
            }
            
            // 在最后一个 </body> 前注入面板（避免注入到 JavaScript 字符串中的 body）
            $bodyClosePos = strripos($result, '</body>');
            
            if ($bodyClosePos !== false) {
                // 找到最后一个 </body>，在其前面注入
                $before = substr($result, 0, $bodyClosePos);
                $after = substr($result, $bodyClosePos);
                $result = $before . $panelHtml . $after;
            } else {
                // 没找到 </body>，直接追加到末尾
                $result = $result . $panelHtml;
            }
            
            // 回写事件数据（dispatch 第二参数是引用变量）
            $payload['result'] = $result;
            $this->writeBackPayload($event, $payload);
            $this->debugLog('panel_injected_success');
            
        } catch (\Exception $e) {
            $this->debugLog('inject_exception', ['message' => $e->getMessage()]);
            $this->logToConsole('error', 'DevToolPanel Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    private function debugLog(string $stage, array $context = []): void
    {
        $base = [
            'stage' => $stage,
            'uri' => $this->request->getUri(),
            'method' => $this->request->getMethod(),
            'isBackend' => $this->request->isBackend(),
        ];
        w_log_warning('[DevToolPanelObserver] ' . $stage, array_merge($base, $context), 'dev_tool_panel');
    }

    /**
     * 兼容两类事件结构：
     * 1) Weline_Framework::telemetry::request_collected: ['data' => [...]]
     * 2) Weline_Framework::App::run_after: ['result' => '...']
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
            $event->setData('data', $payload);
            return;
        }
        $event->setData('result', (string)($payload['result'] ?? ''));
    }

    /**
     * 将请求链路数据注入到 HTML，供前端 DevTool 面板读取。
     */
    private function injectTraceScript(string $result, array $traceSpans): string
    {
        $traceScript = '<script>window.__WELINE_REQUEST_TRACE__=' . json_encode($traceSpans) . ';</script>';
        if (stripos($result, 'window.__WELINE_REQUEST_TRACE__') !== false) {
            // 已存在时覆盖为最新链路，避免 run_after 先注入空数据后 telemetry 无法更新
            $updated = preg_replace(
                '/<script>\s*window\.__WELINE_REQUEST_TRACE__=.*?<\/script>/is',
                $traceScript,
                $result,
                1
            );
            return is_string($updated) ? $updated : $result;
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
     * 渲染开发工具面板
     */
    private function renderPanel(): string
    {
        try {
            // 获取模板文件路径
            $templatePath = dirname(__DIR__) . '/view/hooks/dev-tool-panel.phtml';
            
            if (!is_file($templatePath)) {
                $this->logToConsole('error', 'DevToolPanel: Template file not found: ' . $templatePath);
                return '';
            }
            
            // 检测是否是后端请求（使用Request对象的isBackend方法）
            $isBackend = $this->request->isBackend();
            
            // 检查是否通过Cookie启用（用于在模板中显示关闭按钮）
            $devToolCookieName = Env::get('dev_tool.cookie_name', 'w_dev_tool');
            $hasCookie = !empty(Cookie::get($devToolCookieName)) && Cookie::get($devToolCookieName) === '1';
            
            // 调试：输出检测信息（生产环境可删除）
            // $this->logToConsole('info', 'DevToolPanel Detection: URI=' . $uri . ', isBackend=' . ($isBackend ? 'TRUE' : 'FALSE'));
            
            // 扩展标签/搜索区由各模块通过 Hook 注入，面板本身不包含具体实现
            $extraTabsHtml = '';
            $extraSearchAreasHtml = '';
            try {
                $template = Template::getInstance();
                $extraTabsHtml = $template->getHook(HookInterface::DEVELOPER_WORKSPACE_DEVTOOL_PANEL_TABS_AFTER);
                $extraSearchAreasHtml = $template->getHook(HookInterface::DEVELOPER_WORKSPACE_DEVTOOL_PANEL_SEARCH_AREAS_AFTER);
            } catch (\Throwable $e) {
                // Hook 未实现或未注册时不影响主面板
            }
            // 使用输出缓冲捕获模板输出
            ob_start();
            $panelType = $isBackend ? 'backend' : 'frontend';
            $showCloseButton = $hasCookie; // 只有通过Cookie启用时才显示关闭按钮
            $devToolCookieNameJs = $devToolCookieName; // 传递给模板，供JavaScript使用
            include $templatePath;
            $html = ob_get_clean();
            
            return is_string($html) ? $html : '';
        } catch (\Exception $e) {
            $this->logToConsole('error', 'DevToolPanel Render Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 检查输出是否是 HTML 响应
     */
    private function isHtmlResponse(string $output): bool
    {
        // 首先检查是否是 JSON 响应（JSON 响应不应该注入面板）
        $trimmed = trim($output);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            // 尝试解析 JSON，如果成功则不是 HTML
            json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return false;
            }
        }
        
        // 检查是否是纯文本响应（Content-Type 可能不是 HTML）
        // 如果输出很短且不包含 HTML 标签，可能不是 HTML
        if (strlen($trimmed) < 100 && 
            stripos($trimmed, '<html') === false && 
            stripos($trimmed, '<!doctype') === false &&
            stripos($trimmed, '<body') === false) {
            return false;
        }
        
        // 简单检查：是否包含 HTML 标签
        return (stripos($output, '<html') !== false || 
                stripos($output, '<!doctype') !== false ||
                stripos($output, '<body') !== false);
    }

    /**
     * 输出日志到浏览器控制台
     * 
     * @param string $level 日志级别：error, warn, info, log
     * @param string $message 消息内容
     * @param array $data 额外数据
     */
    private function logToConsole(string $level, string $message, array $data = []): void
    {
        $level = in_array($level, ['error', 'warn', 'info', 'log']) ? $level : 'log';
        
        $output = '<script>';
        $output .= "console.{$level}('[DevToolPanel] " . addslashes($message) . "');";
        
        if (!empty($data)) {
            $output .= "console.{$level}('[DevToolPanel] 详细信息:', " . json_encode($data, JSON_UNESCAPED_UNICODE) . ");";
        }
        
        $output .= '</script>';
        
        echo $output;
    }
}
