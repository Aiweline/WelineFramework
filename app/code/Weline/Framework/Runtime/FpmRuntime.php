<?php
declare(strict_types=1);

/**
 * Weline Framework - FPM 运行时
 * 
 * 传统 PHP-FPM 模式的运行时实现
 * 包装现有 App::run() 逻辑，保持向后兼容
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\App;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core as Router;

/**
 * FPM 运行时
 * 
 * 特点：
 * - 每请求初始化/销毁
 * - 使用超全局变量
 * - 与现有代码完全兼容
 */
class FpmRuntime implements RuntimeInterface
{
    /**
     * 是否已初始化
     */
    private bool $bootstrapped = false;
    
    /**
     * 事件管理器
     */
    private ?EventsManager $eventManager = null;
    
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }
        
        // 初始化框架
        App::init();
        
        // 初始化请求上下文
        RequestContext::init();
        
        $this->bootstrapped = true;
    }
    
    /**
     * @inheritDoc
     */
    public function handle(?Request $request = null): string
    {
        // 确保已初始化
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }
        
        // 获取事件管理器
        if ($this->eventManager === null) {
            $this->eventManager = ObjectManager::getInstance(EventsManager::class);
        }
        
        $_SERVER['WELINE_PARSER_URL'] = true;
        $_SERVER['WELINE_IS_MEDIA'] = false;
        
        try {
            // 触发 run_before 事件
            $this->eventManager->dispatch('Weline_Framework::App::run_before');
            
            $result = '';
            
            if (!CLI) {
                // URL 解析
                $parse = null;
                if ($_SERVER['WELINE_PARSER_URL']) {
                    $parse = Url::parser();
                }
                
                if (\is_array($parse)) {
                    $this->processUrlParse($parse);
                }
                
                // 路由处理
                $result = ObjectManager::getInstance(Router::class)->start();
            }
            
            // 触发 run_after 事件
            $data = new \Weline\Framework\DataObject\DataObject(['result' => $result]);
            $this->eventManager->dispatch('Weline_Framework::App::run_after', $data);
            $result = $data->getData('result');
            
            if (\is_array($result)) {
                return \json_encode($result);
            }
            
            return (string) $result;
            
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            // 捕获响应终止异常，在 FPM 模式下直接发送响应
            $e->emit(true);
            return ''; // 不会执行到这里，emit() 会 exit
        }
    }
    
    /**
     * 处理 URL 解析结果
     */
    private function processUrlParse(array $parse): void
    {
        if ($_SERVER['REQUEST_METHOD'] && isset($parse['uri'])) {
            $uri = Url::decode_url($parse['uri']);
            $parse['server']['REQUEST_URI'] = $uri;
            $parse['server']['QUERY_STRING'] = Url::parse_url($uri, 'query');
        }
        $_SERVER = $parse['server'];
        
        // 设置后端标识
        $welineArea = $_SERVER['WELINE_AREA'] ?? '';
        $_SERVER['WELINE_IS_BACKEND'] = ($welineArea === 'backend' || $welineArea === 'rest_backend');
        
        // 存入请求上下文
        RequestContext::area($welineArea);
        
        // 处理 Cookie
        $default_cookies = [
            'WELINE_USER_LANG',
            'WELINE_USER_CURRENCY',
            'WELINE_WEBSITE_ID',
            'WELINE_WEBSITE_CODE',
            'WELINE_WEBSITE_URL',
        ];
        
        if ($parse['currency']) {
            $_SERVER['WELINE_USER_CURRENCY'] = $parse['currency'];
            RequestContext::currency($parse['currency']);
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_CURRENCY'] = $_SERVER['WELINE_USER_CURRENCY'] ?? RequestContext::currency();
        }
        if ($parse['language']) {
            $_SERVER['WELINE_USER_LANG'] = $parse['language'];
            RequestContext::locale($parse['language']);
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_LANG'] = $_SERVER['WELINE_USER_LANG'] ?? RequestContext::locale();
        }
        
        foreach ($default_cookies as $key) {
            if (!isset($_SERVER[$key])) {
                if (\in_array($key, ['WELINE_WEBSITE_ID', 'WELINE_WEBSITE_CODE'], true)) {
                    $_SERVER[$key] = '';
                } else {
                    throw new \Weline\Framework\App\Exception(__('系统SERVER缺少key：%{1}', $key));
                }
            }
            $currentCookieValue = \Weline\Framework\Http\Cookie::get($key);
            if ($currentCookieValue !== $_SERVER[$key]) {
                \Weline\Framework\Http\Cookie::set($key, $_SERVER[$key], 3600 * 24 * 30, []);
            }
        }
        
        // 存储网站信息到上下文
        if (!empty($_SERVER['WELINE_WEBSITE_ID'])) {
            RequestContext::websiteId((int) $_SERVER['WELINE_WEBSITE_ID']);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        // FPM 模式下，每个请求独立，无需重置
        // 但清理请求上下文
        RequestContext::cleanup();
    }
    
    /**
     * @inheritDoc
     */
    public function terminate(): void
    {
        // 清理请求上下文
        RequestContext::cleanup();
        
        $this->bootstrapped = false;
        $this->eventManager = null;
    }
    
    /**
     * @inheritDoc
     */
    public function getMode(): string
    {
        return self::MODE_FPM;
    }
    
    /**
     * @inheritDoc
     */
    public function isPersistent(): bool
    {
        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }
}
