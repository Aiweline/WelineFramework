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
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;

/**
 * 开发工具面板 Observer
 * 监听 Weline_Framework::App::run_after 事件，在页面输出前注入开发工具面板
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
            return;
        }

        // 如果是 AJAX 请求或接口请求，不显示面板
        if ($this->request->isAjax() || 
            $this->request->isApiFrontend() || 
            $this->request->isApiBackend()) {
            return;
        }

        // 如果是 iframe 请求，不显示面板
        if ($this->request->getParam('isIframe') === 'true' || 
            $this->request->getGet('isIframe') === 'true') {
            return;
        }

        try {
            // 检查已发送的 headers 中是否有 Content-Type: application/json
            $headers = headers_list();
            foreach ($headers as $header) {
                if (stripos($header, 'Content-Type:') !== false && 
                    stripos($header, 'application/json') !== false) {
                    return;
                }
            }
            
            // 获取页面输出结果
            $result = $event->getData('result');
            
            if (empty($result) || !is_string($result)) {
                return;
            }
            
            // 检查是否是 HTML 响应（包含 JSON 检测）
            if (!$this->isHtmlResponse($result)) {
                return;
            }
            
            // 渲染开发工具面板
            $panelHtml = $this->renderPanel();
            
            if (empty($panelHtml)) {
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
            
            // 更新 result
            $event->setData('result', $result);
            
        } catch (\Exception $e) {
            $this->logToConsole('error', 'DevToolPanel Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
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
